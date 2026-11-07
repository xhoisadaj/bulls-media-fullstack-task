<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('sheets:demo-change {scenario : edit|sort|delete|restore|add-column|rename-column|clear|reset}')]
#[Description('Simulate an edit in the demo spreadsheets (Shop London unless noted), to see how the next sync reacts')]
class DemoChangeCommand extends Command
{
    private const SCENARIOS = [
        'edit' => 'Order 1002: price €35 → €40 (a modification found by key)',
        'sort' => 'Rows re-sorted (nothing should change)',
        'delete' => 'Order 1003 deleted (a removal waiting for approval)',
        'restore' => 'Order 1003 back in the sheet (restored, if its removal was approved)',
        'add-column' => 'New "discount" column (an alert; nothing changes until you map it)',
        'rename-column' => 'Shop Paris: "price" renamed to "unit price" (table marked broken, data untouched)',
        'clear' => 'All London rows deleted (the mass-removal guard stops the sync)',
        'reset' => 'Restore all demo spreadsheets to their original content',
    ];

    public function handle(): int
    {
        $scenario = (string) $this->argument('scenario');
        $path = (string) config('sync.demo_path');

        if (! isset(self::SCENARIOS[$scenario])) {
            $this->components->error('Unknown scenario. Choose one of: '.implode(', ', array_keys(self::SCENARIOS)));

            return self::FAILURE;
        }

        if ($scenario === 'reset') {
            File::copyDirectory(database_path('demo'), $path);
            $this->components->info(self::SCENARIOS[$scenario]);

            return self::SUCCESS;
        }

        $file = $scenario === 'rename-column' ? 'demo-shop-paris' : 'demo-shop-london';
        $data = json_decode(File::get($path.'/'.$file.'.json'), true);
        $rows = &$data['tabs'][0]['rows'];

        match ($scenario) {
            'edit' => $this->setWhere($rows, 1002, 3, ['v' => 40, 'f' => '€40.00']),
            'sort' => $rows = [$rows[0], ...array_reverse(array_slice($rows, 1))],
            'delete' => $rows = array_values(array_filter($rows, fn ($row) => ($row[0] ?? null) !== 1003)),
            'restore' => $this->restore($rows),
            'add-column' => $rows = array_map(fn ($row, $i) => [...$row, $i === 0 ? 'discount' : ['v' => 0.1, 'f' => '10%']], $rows, array_keys($rows)),
            'rename-column' => $rows[98][10] = 'unit price',
            'clear' => $rows = [$rows[0]],
        };

        File::put($path.'/'.$file.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->components->info(self::SCENARIOS[$scenario]);
        $this->line('  Now sync: php artisan sheets:sync --now, or "Sync now" in the admin panel.');

        return self::SUCCESS;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function setWhere(array &$rows, int $orderId, int $column, mixed $value): void
    {
        foreach ($rows as $i => $row) {
            if (($row[0] ?? null) === $orderId) {
                $rows[$i][$column] = $value;
            }
        }
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function restore(array &$rows): void
    {
        $original = json_decode(File::get(database_path('demo/demo-shop-london.json')), true)['tabs'][0]['rows'];

        foreach ($original as $row) {
            if (($row[0] ?? null) === 1003 && ! in_array(1003, array_column($rows, 0), true)) {
                $rows[] = array_pad($row, count($rows[0]), '');
            }
        }
    }
}
