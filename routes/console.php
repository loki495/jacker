<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (filled(config('mailscan.username')) && filled(config('mailscan.password'))) {
    Schedule::command('mail:scan')->everyThirtyMinutes()->withoutOverlapping(30)->runInBackground();
}
