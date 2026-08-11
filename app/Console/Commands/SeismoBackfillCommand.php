<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
    protected $description = 'Backfill earthquake data from USGS (Sprint 2)';

    public function handle(): int
    {
        $this->warn('seismo:backfill is not implemented yet — USGS ingest lands in Sprint 2.');

        return self::SUCCESS;
    }
}
