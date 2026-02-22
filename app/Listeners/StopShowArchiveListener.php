<?php

namespace App\Listeners;

use App\Events\ShowEnded;
use Illuminate\Support\Facades\Log;

class StopShowArchiveListener
{
    /**
     * Handle the event.
     * NOT queued — must run synchronously so the flag is removed before
     * CreateVodOnShowEndedListener's queued job fires (ensuring stream-manager.sh
     * stops the archive FFmpeg and writes #EXT-X-ENDLIST in time).
     */
    public function handle(ShowEnded $event): void
    {
        $source = $event->show->source;

        if (! $source) {
            Log::warning('StopShowArchiveListener: show has no source, skipping.', [
                'show_id' => $event->show->id,
            ]);

            return;
        }

        $flagsDir = config('stream.vod.archive_flags_dir', '/var/www/hls/archive-flags');
        $flagFile = "{$flagsDir}/{$source->slug}";

        if (file_exists($flagFile)) {
            unlink($flagFile);

            Log::info('StopShowArchiveListener: removed archive flag.', [
                'show_id'   => $event->show->id,
                'source'    => $source->slug,
                'flag_file' => $flagFile,
            ]);
        } else {
            Log::debug('StopShowArchiveListener: flag file not found (already removed?).', [
                'show_id'   => $event->show->id,
                'source'    => $source->slug,
                'flag_file' => $flagFile,
            ]);
        }
    }
}
