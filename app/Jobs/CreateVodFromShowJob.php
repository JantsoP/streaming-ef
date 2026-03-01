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

