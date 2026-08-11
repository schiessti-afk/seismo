<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillSeismicData;
use Illuminate\Console\Command;

class SeismoBackfillCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'seismo:backfill';

    /**
     * @var string
     */
    protected $description = 'Backfill earthquake data from USGS all_month feed';

    public function handle(): int
    {
        BackfillSeismicData::dispatch();

        $this->info('Backfill job dispatched to Horizon.');

        return self::SUCCESS;
    }
}
