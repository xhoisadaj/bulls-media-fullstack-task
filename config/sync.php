<?php

return [

    /*
    | Time of the daily sync (24h, server timezone).
    */
    'daily_at' => env('SYNC_DAILY_AT', '02:00'),

    /*
    | Mass-removal guard: a sync stops for a table when more than half of its rows
    | would be removed and at least this many rows are affected.
    */
    'mass_removal_min_rows' => 3,

    /*
    | Where demo spreadsheets (JSON) are kept when demo mode is on.
    */
    'demo_path' => storage_path('app/demo'),

    /*
    | Optional address for email notifications (bell notifications are always on).
    */
    'notify_email' => env('SYNC_NOTIFY_EMAIL'),

    /*
    | Maximum number of row problems stored per sync run.
    */
    'max_logged_problems' => 200,
];
