<?php

namespace App\Listeners;

use App\Events\ShowWentLive;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class StartShowArchiveListener
{
    /**
     * Handle the event.
     * NOT queued — must run synchronously so the flag file exists on the next
     * stream-manager.sh poll (every 5 s) without any queue-worker delay.
     */
    public function handle(ShowWentLive $event): void
    {
        $source = $event->show->source;

        if (! $source) {
            Log::warning('StartShowArchiveListener: show has no source, skipping.', [
                'show_id' => $event->show->id,
            ]);

            return;
        }

        $flagsDir = config('stream.vod.archive_flags_dir', '/var/www/hls/archive-flags');

        File::ensureDirectoryExists($flagsDir);

        $flagFile = "{$flagsDir}/{$source->slug}";
        touch($flagFile);

        Log::info('StartShowArchiveListener: created archive flag.', [
            'show_id'   => $event->show->id,
            'source'    => $source->slug,
            'flag_file' => $flagFile,
        ]);
    }
}
