<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class BackfillState
{
    public const MARKER_KEY = 'seismo.backfill_completed';

    public const LOCK_KEY = 'seismo.backfill';

    public const LOCK_SECONDS = 600;

    public static function isComplete(): bool
    {
        return Cache::has(self::MARKER_KEY);
    }

    public static function markComplete(): void
    {
        Cache::forever(self::MARKER_KEY, true);
    }

    public static function clearMarker(): void
    {
        Cache::forget(self::MARKER_KEY);
    }
}
