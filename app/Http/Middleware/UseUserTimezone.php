<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows every date and time in the admin panel in the signed-in user's timezone.
 */
class UseUserTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($timezone = $request->user()?->timezone) {
            FilamentTimezone::set($timezone);
        }

        return $next($request);
    }
}
