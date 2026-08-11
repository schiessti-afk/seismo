<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Earthquake;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EarthquakeDetected implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Earthquake $earthquake,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('earthquakes'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->earthquake->id,
            'usgs_id' => $this->earthquake->usgs_id,
            'magnitude' => $this->earthquake->magnitude !== null
                ? (float) $this->earthquake->magnitude
                : null,
            'lat' => $this->earthquake->latitude,
            'lon' => $this->earthquake->longitude,
            'depth_km' => $this->earthquake->depth_km,
            'place' => $this->earthquake->place,
            'occurred_at' => $this->earthquake->occurred_at->utc()->toIso8601String(),
            'tsunami' => (bool) $this->earthquake->tsunami,
        ];
    }
}
