<?php

namespace App\Console\Commands;

use App\Exports\TableExporter;
use App\Models\ManagedTable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('sheets:export {table : Target table name, e.g. sales} {--format=xlsx : xlsx, csv or json} {--to= : Where to save the file (default: storage/app/exports)}')]
#[Description('Export a target table to Excel, CSV or JSON')]
class ExportTableCommand extends Command
{
    public function handle(TableExporter $exporter): int
    {
        $table = ManagedTable::query()->where('name', $this->argument('table'))->first();
        $format = (string) $this->option('format');

        if ($table === null) {
            $this->components->error('No target table named "'.$this->argument('table').'".');

            return self::FAILURE;
        }

        if (! isset(TableExporter::FORMATS[$format])) {
            $this->components->error('Format must be one of: '.implode(', ', array_keys(TableExporter::FORMATS)).'.');

            return self::FAILURE;
        }

        $path = $exporter->export($table, $format);

        if ($to = $this->option('to')) {
            File::move($path, $to);
            $path = $to;
        }

        $this->components->info('Exported '.$table->name.' to '.$path);

        return self::SUCCESS;
    }
}
