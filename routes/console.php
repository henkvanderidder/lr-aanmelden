<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessLaptopAanmelden;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// TODO: Schedule the ProcessLaptopAanmelden job to run every 5 minutes
// Schedule::job(new ProcessLaptopAanmelden())->everyFiveMinutes();
