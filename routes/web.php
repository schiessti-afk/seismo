<?php

declare(strict_types=1);

use App\Http\Controllers\EarthquakeExportController;
use App\Livewire\LiveMonitor;
use Illuminate\Support\Facades\Route;

Route::get('/', LiveMonitor::class)->name('home');

Route::middleware('throttle:exports')->group(function (): void {
    Route::get('/export/csv', [EarthquakeExportController::class, 'csv'])->name('export.csv');
    Route::get('/export/geojson', [EarthquakeExportController::class, 'geojson'])->name('export.geojson');
});
