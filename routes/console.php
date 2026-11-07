<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sheets:sync --scheduled')
    ->dailyAt((string) config('sync.daily_at'))
    ->withoutOverlapping()
    ->onOneServer();
