<?php

namespace App\Jobs;

use App\Models\Recording;
use App\Models\Show;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateVodFromShowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry once on transient S3 errors. The job is idempotent.
     */
    public int $tries = 2;

    /**
     * For a 2-hour show at 3 qualities (~6 Mbit avg) that is ~5 GB.
     * S3 upload on a fast link should complete well within 30 minutes.
     */
    public int $timeout = 1800; // 30 minutes

    public function __construct(public Show $show) {}

    public function handle(): void
    {
        $show = $this->show->fresh(['source', 'recording']);

        if (! $show) {
            Log::warning('CreateVodFromShowJob: show not found, skipping');
            return;
        }

        if (! $show->recordable) {
            Log::info("CreateVodFromShowJob: show {$show->id} is not recordable, skipping");
            return;
        }

        if ($show->recording) {
            Log::info("CreateVodFromShowJob: Recording already exists for show {$show->id}, skipping");
            return;
        }

        $source = $show->source;
        if (! $source) {
            Log::warning("CreateVodFromShowJob: show {$show->id} has no source attached, skipping");
            return;
        }

        $sourceSlug  = $source->slug;
        $showSlug    = $show->slug;
        $eventSlug   = config('stream.dvr.event_slug', 'event');
        $vodBasePath = config('stream.dvr.vod_base_path', 'on-demand');
        $s3Prefix    = "{$vodBasePath}/{$eventSlug}/{$showSlug}";
        $archiveDir  = rtrim(config('stream.vod.archive_base_dir', '/var/www/hls/archive'), '/') . "/{$sourceSlug}";

        Log::info("CreateVodFromShowJob: starting VOD upload for show {$show->id}", [
            'archive_dir' => $archiveDir,
            's3_prefix'   => $s3Prefix,
        ]);

        // ── Step 1: Verify archive directory exists and has content ─────────────
        if (! is_dir($archiveDir)) {
            throw new \RuntimeException(
                "Archive directory not found: {$archiveDir}. " .
                "Check that origin-ffmpeg-hls is running and shares the hls-content volume."
            );
        }

        $localFiles = File::allFiles($archiveDir);
        if (empty($localFiles)) {
            throw new \RuntimeException("Archive directory is empty: {$archiveDir}");
        }

        $masterFilename = "{$sourceSlug}_master.m3u8";
        $masterLocalPath = "{$archiveDir}/{$masterFilename}";
        if (! file_exists($masterLocalPath)) {
            throw new \RuntimeException(
                "Master playlist not found: {$masterLocalPath}. " .
                "Archive FFmpeg may still be writing #EXT-X-ENDLIST — retrying."
            );
        }

        // Confirm #EXT-X-ENDLIST is present in at least one variant playlist,
        // meaning FFmpeg has fully flushed the archive output.
        $variantPlaylists = array_filter($localFiles, fn ($f) =>
            str_ends_with($f->getFilename(), '.m3u8') && $f->getFilename() !== $masterFilename
        );
        $endlistFound = false;
        foreach ($variantPlaylists as $pl) {
            if (str_contains(file_get_contents($pl->getRealPath()), '#EXT-X-ENDLIST')) {
                $endlistFound = true;
                break;
            }
        }
        if (! $endlistFound) {
            throw new \RuntimeException(
                "#EXT-X-ENDLIST not found in any variant playlist — " .
                "archive FFmpeg has not finished writing. Will retry."
            );
        }

        $localCount = count($localFiles);
        Log::info("CreateVodFromShowJob: archive OK, {$localCount} files ready for upload");

        // ── Step 2: Upload all archive files to S3 ──────────────────────────────
        $disk = Storage::disk('dvr');

        foreach ($localFiles as $file) {
            $s3Key = "{$s3Prefix}/{$file->getRelativePathname()}";
            $disk->putFileAs(
                dirname($s3Key),
                $file->getRealPath(),
                basename($s3Key),
                'public'
            );
        }

        Log::info("CreateVodFromShowJob: upload complete, verifying S3 integrity");

        // ── Step 3: Verify S3 upload before touching local files ────────────────
        // Check 1: master playlist must exist on S3
        $masterS3Key = "{$s3Prefix}/{$masterFilename}";
        if (! $disk->exists($masterS3Key)) {
            throw new \RuntimeException(
                "S3 verification failed: master playlist missing at {$masterS3Key}"
            );
        }

        // Check 2: S3 file count must match local file count
        $s3Files = $disk->allFiles($s3Prefix);
        $s3Count = count($s3Files);
        if ($s3Count < $localCount) {
            throw new \RuntimeException(
                "S3 verification failed: expected {$localCount} files, found {$s3Count} at {$s3Prefix}"
            );
        }

        // Check 3: master m3u8 byte size must match (within 64 bytes for line-ending differences)
        $localMasterSize = filesize($masterLocalPath);
        $s3MasterSize    = $disk->size($masterS3Key);
        if (abs($localMasterSize - $s3MasterSize) > 64) {
            throw new \RuntimeException(
                "S3 verification failed: master playlist size mismatch " .
                "(local {$localMasterSize}B vs S3 {$s3MasterSize}B)"
            );
        }

        Log::info("CreateVodFromShowJob: S3 integrity verified", [
            'local_files' => $localCount,
            's3_files'    => $s3Count,
            'master_size' => $s3MasterSize,
        ]);

        // ── Step 4: Safe to delete local archive ────────────────────────────────
        File::deleteDirectory($archiveDir);
        Log::info("CreateVodFromShowJob: local archive deleted — {$archiveDir}");

        // ── Step 5: Create Recording row ─────────────────────────────────────────
        // RecordingObserver::created() auto-dispatches ProcessRecordingJob
        // which reads the m3u8 to extract real duration and generate a thumbnail.
        $m3u8Url = $disk->url($masterS3Key);

        Recording::create([
            'show_id'        => $show->id,
            'title'          => $show->title,
            'description'    => $show->description,
            'm3u8_url'       => $m3u8Url,
            'date'           => $show->actual_start,
            'is_published'   => true,
            'required_roles' => $show->required_roles ?? null,
        ]);

        Log::info("CreateVodFromShowJob: VOD Recording created for show {$show->id}", [
            'url' => $m3u8Url,
        ]);
    }
}

class CreateVodFromShowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Two retries; the job is idempotent so retrying is safe.
     */
    public int $tries = 2;

    /**
     * HLS re-encoding of a full show can take a while on slower hardware.
     */
    public int $timeout = 7200; // 2 hours

    public function __construct(public Show $show) {}

    public function handle(DvrExtractorService $extractor): void
    {
        $show = $this->show->fresh(['source', 'recording']);

        if (! $show) {
            Log::warning('CreateVodFromShowJob: show not found, skipping');
            return;
        }

        if (! $show->recordable) {
            Log::info("CreateVodFromShowJob: show {$show->id} is not recordable, skipping");
            return;
        }

        // Idempotency guard — nothing to do if a Recording already exists
        if ($show->recording) {
            Log::info("CreateVodFromShowJob: Recording already exists for show {$show->id}, skipping");
            return;
        }

        if (! $show->actual_start || ! $show->actual_end) {
            Log::warning("CreateVodFromShowJob: show {$show->id} is missing actual_start or actual_end, skipping");
            return;
        }

        $source = $show->source;
        if (! $source) {
            Log::warning("CreateVodFromShowJob: show {$show->id} has no source attached, skipping");
            return;
        }

        $showSlug      = $show->slug;
        $eventSlug     = config('stream.dvr.event_slug', 'event');
        $vodBasePath   = config('stream.dvr.vod_base_path', 'on-demand');
        $s3Prefix      = "{$vodBasePath}/{$eventSlug}/{$showSlug}";
        $convertScript = base_path('dvr-convert.sh');
        $workDir       = storage_path("app/temp/vod/{$showSlug}");
        $extractedFile = "{$workDir}/{$showSlug}.mp4";
        $hlsDir        = "{$workDir}/hls";

        Log::info("CreateVodFromShowJob: starting VOD creation for show {$show->id}", [
            'source'    => $source->slug,
            'start'     => $show->actual_start->toIso8601String(),
            'end'       => $show->actual_end->toIso8601String(),
            's3_prefix' => $s3Prefix,
        ]);

        try {
            File::ensureDirectoryExists($workDir);
            File::ensureDirectoryExists($hlsDir);

            // ── Step 1: Extract DVR segments from S3 into a trimmed MP4 ─────────────
            // DvrExtractorService downloads the SRS DVR .mp4 segments (written by the
            // origin-srs DVR feature) from the 'dvr' S3 disk, concatenates them with
            // ffmpeg -c copy, and trims to the exact show time range.
            // Passing 'local' stores the result in storage/app/dvr-exports/{filename}.
            $extractor->extract(
                stream:         $source->slug,
                startTime:      $show->actual_start,
                endTime:        $show->actual_end,
                outputFilename: "{$showSlug}.mp4",
                targetStorage:  'local',
            );

            $extractorOutput = storage_path("app/dvr-exports/{$showSlug}.mp4");
            if (! file_exists($extractorOutput)) {
                throw new \RuntimeException("DVR extraction produced no output at {$extractorOutput}");
            }

            rename($extractorOutput, $extractedFile);
            Log::info("CreateVodFromShowJob: extraction complete", [
                'file' => $extractedFile,
                'size' => filesize($extractedFile),
            ]);

            // ── Step 2: Convert to multi-bitrate ABR HLS using dvr-convert.sh ───────
            // dvr-convert.sh produces 360p / 480p / 720p / 1080p variants plus a master
            // playlist named {input_no_ext}_master.m3u8 (i.e. {showSlug}_master.m3u8).
            if (! file_exists($convertScript)) {
                throw new \RuntimeException("dvr-convert.sh not found at {$convertScript}");
            }

            Log::info("CreateVodFromShowJob: converting to multi-bitrate HLS (this may take a while)");
            $result = Process::timeout(3600)->run(['bash', $convertScript, $extractedFile, $hlsDir]);

            if (! $result->successful()) {
                throw new \RuntimeException("dvr-convert.sh failed:\n" . $result->errorOutput());
            }

            // ── Step 3: Upload all HLS files to the DVR S3 bucket ───────────────────
            $disk  = Storage::disk('dvr');
            $files = File::allFiles($hlsDir);

            if (empty($files)) {
                throw new \RuntimeException("dvr-convert.sh produced no output files in {$hlsDir}");
            }

            Log::info("CreateVodFromShowJob: uploading " . count($files) . " HLS files to {$s3Prefix}/");

            foreach ($files as $file) {
                $s3Key = "{$s3Prefix}/{$file->getRelativePathname()}";
                $disk->putFileAs(
                    dirname($s3Key),
                    $file->getRealPath(),
                    basename($s3Key),
                    'public'
                );
            }

            // ── Step 4: Build public master playlist URL ─────────────────────────────
            $masterS3Key = "{$s3Prefix}/{$showSlug}_master.m3u8";
            $m3u8Url     = $disk->url($masterS3Key);

            Log::info("CreateVodFromShowJob: upload complete", ['master_url' => $m3u8Url]);

            // ── Step 5: Create the Recording row ────────────────────────────────────
            // RecordingObserver::created() then auto-dispatches ProcessRecordingJob
            // which reads the m3u8 to extract real duration and generates a thumbnail.
            Recording::create([
                'show_id'        => $show->id,
                'title'          => $show->title,
                'description'    => $show->description,
                'm3u8_url'       => $m3u8Url,
                'date'           => $show->actual_start,
                'is_published'   => true,
                'required_roles' => $show->required_roles ?? null,
            ]);

            Log::info("CreateVodFromShowJob: VOD Recording created successfully for show {$show->id}");

        } catch (\Throwable $e) {
            Log::error("CreateVodFromShowJob: failed for show {$show->id}: {$e->getMessage()}", [
                'exception' => $e->getMessage(),
            ]);
            throw $e; // Re-throw so Horizon marks it failed and retries
        } finally {
            // Always clean up local temp files
            if (File::exists($workDir)) {
                File::deleteDirectory($workDir);
            }
        }
    }
}
