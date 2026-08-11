<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\EarthquakeDetected;
use App\Models\Earthquake;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SeismoBroadcastTestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'seismo:broadcast-test';

    /**
     * @var string
     */
    protected $description = 'Dispatch a synthetic EarthquakeDetected event for Reverb smoke testing';

    public function handle(): int
    {
        $usgsId = 'seismo-smoke-'.now()->format('YmdHis');

        $earthquake = Earthquake::factory()->create([
            'usgs_id' => $usgsId,
            'magnitude' => 4.5,
            'place' => 'Seismo broadcast smoke test',
            'latitude' => 35.65,
            'longitude' => -117.65,
            'depth_km' => 8.5,
            'tsunami' => false,
            'occurred_at' => Carbon::now('UTC'),
        ]);

        EarthquakeDetected::dispatch($earthquake->fresh() ?? $earthquake);

        $this->info("Dispatched EarthquakeDetected for {$usgsId}. Check browser consoles on the public page.");

        return self::SUCCESS;
    }
}
