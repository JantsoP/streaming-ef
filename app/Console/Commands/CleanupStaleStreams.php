<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\Show;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupStaleStreams extends Command
{
    protected $signature = 'streams:cleanup
                            {--dry-run : Show what would be removed without actually removing anything}
                            {--force : Remove flags even if the source still exists (use when source is not streaming)}';

    protected $description = 'Remove stale archive/pause flags for deleted shows or inactive sources, stopping orphaned archive FFmpeg processes';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $force    = $this->option('force');

        $flagsDir = rtrim(config('stream.vod.archive_flags_dir', '/var/www/hls/archive-flags'), '/');
        $pauseDir = rtrim(config('stream.vod.archive_pause_dir', '/var/www/hls/archive-pause'), '/');

        if (! is_dir($flagsDir)) {
            $this->warn("Archive flags directory not found: {$flagsDir}");
            $this->warn('Make sure this command runs inside the laravel.test container (sail artisan streams:cleanup)');
            return Command::FAILURE;
        }

        $flagFiles = glob("{$flagsDir}/*") ?: [];

        if (empty($flagFiles)) {
            $this->info('No archive flags found — nothing to clean up.');
            return Command::SUCCESS;
        }

        $this->info('Checking ' . count($flagFiles) . ' archive flag(s)...');
        $this->newLine();

        // Get all active source slugs with a currently live show
        $activeSourceSlugs = Source::whereHas('shows', function ($q) {
            $q->where('status', 'live');
        })->pluck('slug')->toArray();

        // Get all existing source slugs (source not deleted)
        $existingSourceSlugs = Source::pluck('slug')->toArray();

        $rows = [];
        $removedCount = 0;

        foreach ($flagFiles as $flagFile) {
            $slug      = basename($flagFile);
            $hasPause  = file_exists("{$pauseDir}/{$slug}");
            $sourceExists = in_array($slug, $existingSourceSlugs);
            $isLive    = in_array($slug, $activeSourceSlugs);

            if (! $sourceExists) {
                $reason = 'Source deleted';
                $action = 'REMOVE';
            } elseif ($force && ! $isLive) {
                $reason = 'Source exists but no live show (--force)';
                $action = 'REMOVE';
            } elseif (! $isLive) {
                $reason = 'Source exists but no live show';
                $action = 'SKIP (use --force to remove)';
            } else {
                $reason = 'Show is live — OK';
                $action = 'KEEP';
            }

            $status = $hasPause ? 'paused' : 'recording';
            $rows[] = [$slug, $status, $reason, $action];

            if ($action === 'REMOVE') {
                if (! $isDryRun) {
                    File::delete($flagFile);
                    if ($hasPause) {
                        File::delete("{$pauseDir}/{$slug}");
                    }
                    $this->line("  <fg=red>Removed flag:</> {$slug}" . ($hasPause ? ' (+ pause flag)' : ''));
                } else {
                    $this->line("  <fg=yellow>[dry-run] Would remove flag:</> {$slug}" . ($hasPause ? ' (+ pause flag)' : ''));
                }
                $removedCount++;
            }
        }

        $this->table(['Slug', 'Status', 'Reason', 'Action'], $rows);
        $this->newLine();

        if ($isDryRun) {
            $this->info("Dry run complete. {$removedCount} flag(s) would be removed. Run without --dry-run to apply.");
        } else {
            $this->info("{$removedCount} stale flag(s) removed.");
            if ($removedCount > 0) {
                $this->line('stream-manager.sh will detect the removed flags within 5 seconds and stop the orphaned archive FFmpeg process(es).');
            }
        }

        // Show currently active flags after cleanup
        $remaining = glob("{$flagsDir}/*") ?: [];
        if (! empty($remaining)) {
            $this->newLine();
            $this->info('Remaining active flags: ' . implode(', ', array_map('basename', $remaining)));
        }

        return Command::SUCCESS;
    }
}
