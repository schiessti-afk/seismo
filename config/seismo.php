<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | USGS feed URLs
    |--------------------------------------------------------------------------
    */

    'usgs_backfill_feed_url' => env(
        'USGS_BACKFILL_FEED_URL',
        'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_month.geojson',
    ),

    'usgs_live_feed_url' => env(
        'USGS_LIVE_FEED_URL',
        'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson',
    ),

    /*
    |--------------------------------------------------------------------------
    | Ingest / live window
    |--------------------------------------------------------------------------
    */

    'ingest_seconds' => (int) env('SEISMO_INGEST_SECONDS', 60),

    'backfill_on_boot' => (bool) env('SEISMO_BACKFILL_ON_BOOT', true),

    'live_window_hours' => (int) env('SEISMO_LIVE_WINDOW_HOURS', 24),

    'live_window_presets' => array_map(
        'intval',
        explode(',', (string) env('SEISMO_LIVE_WINDOW_PRESETS', '1,3,6,12,24,48,168')),
    ),

    'history_slice_hours' => (int) env('SEISMO_HISTORY_SLICE_HOURS', 6),

    'list_page_size' => (int) env('SEISMO_LIST_PAGE_SIZE', 15),

    /*
    |--------------------------------------------------------------------------
    | Magnitude gates
    |--------------------------------------------------------------------------
    */

    'broadcast_min_magnitude' => (float) env('SEISMO_BROADCAST_MIN_MAGNITUDE', 2.5),

    'default_filter_min_magnitude' => (float) env('SEISMO_DEFAULT_FILTER_MIN_MAGNITUDE', 2.5),

];
