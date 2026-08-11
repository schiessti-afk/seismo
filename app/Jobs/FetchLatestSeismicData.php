<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Usgs\IngestUsgsFeed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FetchLatestSeismicData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(IngestUsgsFeed $ingest): void
    {
        try {
            $result = $ingest->ingest(
                (string) config('seismo.usgs_live_feed_url'),
                timeoutSeconds: 30,
                broadcast: true,
            );

            if (! $result->successful) {
                Log::warning('Live ingest failed — will retry on next schedule tick');
            }
        } catch (\Throwable $exception) {
            Log::warning('Live ingest error — soft fail', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
