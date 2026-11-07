<?php

namespace App\Providers;

use App\Google\Contracts\SheetsGateway;
use App\Google\GoogleSheetsGateway;
use App\Google\InMemorySheetsGateway;
use App\Models\GoogleSetting;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Demo mode reads JSON spreadsheets from storage instead of Google.
        $this->app->scoped(SheetsGateway::class, function ($app): SheetsGateway {
            return GoogleSetting::current()->use_demo_data
                ? new InMemorySheetsGateway(config('sync.demo_path'))
                : $app->make(GoogleSheetsGateway::class);
        });
    }

    public function boot(): void
    {
        //
    }
}
