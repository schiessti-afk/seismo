<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Earthquake;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Earthquake>
 */
class EarthquakeFactory extends Factory
{
    protected $model = Earthquake::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurredAt = Carbon::createFromTimestampMs(
            fake()->numberBetween(1_700_000_000_000, 1_750_000_000_000)
        );

        $latitude = fake()->latitude(-60, 60);
        $longitude = fake()->longitude(-180, 180);
        $magnitude = fake()->randomFloat(1, 1.0, 7.5);
        $usgsId = fake()->unique()->regexify('[a-z]{2}[0-9]{6}[a-z]');

        return [
            'usgs_id' => $usgsId,
            'magnitude' => $magnitude,
            'mag_type' => fake()->randomElement(['ml', 'mw', 'mb', 'md']),
            'place' => fake()->city().', '.fake()->country(),
            'depth_km' => fake()->randomFloat(1, 0.1, 700),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'tsunami' => fake()->boolean(10),
            'status' => fake()->randomElement(['automatic', 'reviewed']),
            'url' => 'https://earthquake.usgs.gov/earthquakes/eventpage/'.$usgsId,
            'occurred_at' => $occurredAt,
            'usgs_updated_at' => $occurredAt->copy()->addMinutes(fake()->numberBetween(1, 120)),
            'recorded_at' => now(),
            'raw' => [
                'type' => 'Feature',
                'id' => $usgsId,
                'properties' => [
                    'mag' => $magnitude,
                    'place' => fake()->city(),
                    'time' => $occurredAt->getTimestampMs(),
                    'updated' => $occurredAt->getTimestampMs(),
                    'tsunami' => 0,
                ],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$longitude, $latitude, fake()->randomFloat(1, 0.1, 50)],
                ],
            ],
        ];
    }
}
