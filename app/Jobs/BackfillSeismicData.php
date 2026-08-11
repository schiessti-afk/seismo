<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Usgs\IngestUsgsFeed;
use App\Support\BackfillState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BackfillSeismicData implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function handle(IngestUsgsFeed $ingest): void
    {
        if (BackfillState::isComplete()) {
            return;
        }

        $lock = Cache::lock(BackfillState::LOCK_KEY, BackfillState::LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('Backfill skipped — lock held by another worker');

            return;
        }

        try {
            if (BackfillState::isComplete()) {
                return;
            }

            $result = $ingest->ingest(
                (string) config('seismo.usgs_backfill_feed_url'),
                timeoutSeconds: 120,
            );

            if ($result->successful) {
                BackfillState::markComplete();

                Log::info('Backfill completed', [
                    'upserted' => $result->upserted,
                    'skipped' => $result->skipped,
                ]);
            } else {
                Log::warning('Backfill failed — marker left unset for retry');
            }
        } finally {
            $lock->release();
        }
    }
}
