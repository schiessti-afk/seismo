<?php

use App\Jobs\BackfillSeismicData;
use App\Jobs\FetchLatestSeismicData;
use App\Support\BackfillState;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new FetchLatestSeismicData)->everyMinute();

        $schedule->call(function (): void {
            if (! config('seismo.backfill_on_boot')) {
                return;
            }

            if (BackfillState::isComplete()) {
                return;
            }

            BackfillSeismicData::dispatch();
        })->everyMinute();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
