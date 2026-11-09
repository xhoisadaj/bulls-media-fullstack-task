<?php

use Illuminate\Support\Facades\Schedule;

// Sync schedules (Activity → Sync schedules) are checked every minute; each knows its own times,
// weekdays and timezone. A default "every day at 02:00, all files" schedule is created on install.
Schedule::command('sheets:run-schedules')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
