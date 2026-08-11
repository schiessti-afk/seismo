<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EarthquakeFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $usgs_id
 * @property float|null $magnitude
 * @property string|null $mag_type
 * @property string|null $place
 * @property float|null $depth_km
 * @property bool $tsunami
 * @property string|null $status
 * @property string|null $url
 * @property Carbon $occurred_at
 * @property Carbon|null $usgs_updated_at
 * @property Carbon $recorded_at
 * @property array<string, mixed> $raw
 * @property float|null $latitude
 * @property float|null $longitude
 */
class Earthquake extends Model
{
    /** @use HasFactory<EarthquakeFactory> */
    use HasFactory;

    private ?float $pendingLatitude = null;

    private ?float $pendingLongitude = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'usgs_id',
        'magnitude',
        'mag_type',
        'place',
        'depth_km',
        'tsunami',
        'status',
        'url',
        'occurred_at',
        'usgs_updated_at',
        'recorded_at',
        'raw',
        'latitude',
        'longitude',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('withCoordinates', function (Builder $query): void {
            $query->selectRaw('earthquakes.*, ST_Y(location::geometry) AS latitude, ST_X(location::geometry) AS longitude');
        });

        static::saving(function (Earthquake $earthquake): void {
            $earthquake->applyPendingLocation();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'magnitude' => 'decimal:2',
            'depth_km' => 'float',
            'tsunami' => 'boolean',
            'occurred_at' => 'datetime',
            'usgs_updated_at' => 'datetime',
            'recorded_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function setAttribute($key, $value): mixed
    {
        if ($key === 'latitude') {
            $this->pendingLatitude = $value !== null ? (float) $value : null;

            return $this;
        }

        if ($key === 'longitude') {
            $this->pendingLongitude = $value !== null ? (float) $value : null;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function getLatitudeAttribute(): ?float
    {
        if ($this->pendingLatitude !== null) {
            return $this->pendingLatitude;
        }

        return isset($this->attributes['latitude'])
            ? (float) $this->attributes['latitude']
            : null;
    }

    public function getLongitudeAttribute(): ?float
    {
        if ($this->pendingLongitude !== null) {
            return $this->pendingLongitude;
        }

        return isset($this->attributes['longitude'])
            ? (float) $this->attributes['longitude']
            : null;
    }

    private function applyPendingLocation(): void
    {
        if ($this->pendingLatitude === null || $this->pendingLongitude === null) {
            return;
        }

        $lon = $this->pendingLongitude;
        $lat = $this->pendingLatitude;

        $this->attributes['location'] = DB::raw(
            "ST_SetSRID(ST_MakePoint({$lon}, {$lat}), 4326)::geography"
        );
    }

    /**
     * @param  Builder<Earthquake>  $query
     */
    #[Scope]
    protected function magnitudeBetween(Builder $query, ?float $min, ?float $max): void
    {
        if ($min !== null) {
            $query->where('magnitude', '>=', $min);
        }

        if ($max !== null) {
            $query->where('magnitude', '<=', $max);
        }
    }

    /**
     * @param  Builder<Earthquake>  $query
     */
    #[Scope]
    protected function depthBetween(Builder $query, ?float $minKm, ?float $maxKm): void
    {
        if ($minKm !== null) {
            $query->where('depth_km', '>=', $minKm);
        }

        if ($maxKm !== null) {
            $query->where('depth_km', '<=', $maxKm);
        }
    }

    /**
     * @param  Builder<Earthquake>  $query
     */
    #[Scope]
    protected function occurredBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('occurred_at', [$from, $to]);
    }

    /**
     * @param  Builder<Earthquake>  $query
     */
    #[Scope]
    protected function withinRadius(Builder $query, float $lat, float $lon, float $km): void
    {
        $meters = $km * 1000;

        $query->whereRaw(
            'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$lon, $lat, $meters]
        );
    }

    /**
     * @param  Builder<Earthquake>  $query
     */
    #[Scope]
    protected function tsunami(Builder $query, string $enum = 'all'): void
    {
        match ($enum) {
            'yes' => $query->where('tsunami', true),
            'no' => $query->where('tsunami', false),
            default => null,
        };
    }

    /**
     * @param  Builder<Earthquake>  $query
     */
    #[Scope]
    protected function placeLike(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $query->where('place', 'ilike', '%'.$search.'%');
    }

    /**
     * @param  Builder<Earthquake>  $query
     */
    #[Scope]
    protected function orderByOccurredDesc(Builder $query): void
    {
        $query->orderByDesc('occurred_at');
    }
}
