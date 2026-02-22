<?php

namespace App\Listeners;

use App\Events\ShowEnded;
use App\Jobs\CreateVodFromShowJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class CreateVodOnShowEndedListener implements ShouldQueue
{
    public string $queue = 'recordings';

    public function handle(ShowEnded $event): void
    {
        $show = $event->show;

        // Wait just long enough for archive FFmpeg to receive SIGTERM and write
        // #EXT-X-ENDLIST to all variant playlists (usually < 5 seconds).
        // The job itself re-checks for #EXT-X-ENDLIST and will throw + retry if
        // it is not yet present, so this delay is purely a "head start".
        $delaySeconds = config('stream.vod.delay_seconds', 15);

        Log::info("CreateVodOnShowEndedListener: scheduling VOD job for show {$show->id} in {$delaySeconds}s");

        CreateVodFromShowJob::dispatch($show)
            ->onQueue('recordings')
            ->delay(now()->addSeconds($delaySeconds));
    }
}
