<?php

namespace App\Console\Commands;

use App\Demo\DemoData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sheets:demo {--fresh : Start over: remove the demo and load it again} {--remove : Remove the demo spreadsheets and the demo_ tables}')]
#[Description('Load the demo: 3 spreadsheets read from JSON (no Google account needed) into demo_ tables, with a first sync')]
class DemoCommand extends Command
{
    public function handle(DemoData $demo): int
    {
        if ($this->option('remove')) {
            $removed = $demo->remove();
            $this->components->info('Removed '.$removed['spreadsheets'].' demo spreadsheet(s) and the tables '.(implode(', ', $removed['tables']) ?: 'none').'.');

            return self::SUCCESS;
        }

        if (DemoData::isLoaded() && ! $this->option('fresh')) {
            $this->components->info('Demo already loaded. Use --fresh to start over, or --remove to take it out.');

            return self::SUCCESS;
        }

        foreach ($demo->load() as $name => $result) {
            $this->components->twoColumnDetail($name, $result);
        }

        $this->newLine();
        $this->components->info('Demo loaded into demo_sales, demo_customers and demo_returns. Your own files and tables are not touched.');
        $this->components->bulletList([
            'Simulate sheet edits with: php artisan sheets:demo-change {edit|sort|delete|restore|add-column|rename-column|clear|reset}',
            'Then click "Sync now" (or run php artisan sheets:sync --now) and look at the review inbox and alerts.',
        ]);

        return self::SUCCESS;
    }
}
