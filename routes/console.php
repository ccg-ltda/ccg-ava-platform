<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Picks up chatbot executions a crash left half done (never resends a message: see ChatbotExecutions::recover).
Schedule::command('executions:recover')->everyMinute()->withoutOverlapping()->onOneServer();
