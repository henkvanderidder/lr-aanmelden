<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Jobs\TestJob;
use App\Jobs\ProcessLaptopAanmelden;
use Illuminate\Support\Facades\Log;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    /*
    ->withSchedule(function (Schedule $schedule): void {
        //
        $schedule->call (function () {
            //
            Log::info('Schedule: job dispatched '.date("Y-m-d H:i:s").'.');
            TestJob::dispatch();
            ProcessLaptopAanmelden::dispatch();
       })->everyFiveMinutes();
    })
       */
    ->create();
