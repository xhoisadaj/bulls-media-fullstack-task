<?php

namespace App\Providers;

use App\Google\Contracts\SheetsGateway;
use App\Google\GoogleSheetsGateway;
use App\Google\InMemorySheetsGateway;
use App\Google\RoutingSheetsGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Demo spreadsheets ("demo-…") are read from JSON files in storage, every other one from Google.
        $this->app->scoped(SheetsGateway::class, fn ($app): SheetsGateway => new RoutingSheetsGateway(
            new InMemorySheetsGateway(config('sync.demo_path')),
            fn (): SheetsGateway => $app->make(GoogleSheetsGateway::class),
        ));
    }

    public function boot(): void
    {
        //
    }
}
