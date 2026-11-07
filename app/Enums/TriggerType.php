<?php

namespace App\Enums;

enum TriggerType: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Backfill = 'backfill';
}
