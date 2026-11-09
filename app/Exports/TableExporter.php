<?php

namespace App\Exports;

use App\Models\ManagedTable;
use App\Models\SourceRecord;
use App\Sync\SchemaManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Exports a target table (e.g. sales) to Excel, CSV or JSON: our row ID plus the table's columns,
 * in ID order. Optionally only the rows that came from one sheet table.
 */
class TableExporter
{
    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)', 'json' => 'JSON (.json)'];

    private const NUMERIC_TYPES = ['bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint', 'decimal', 'double', 'float'];

    public function __construct(private readonly SchemaManager $schema) {}

    /**
     * Writes the export to a temporary file and returns its path. The caller deletes it after sending.
     *
     * @param  int|list<int>|null  $sourceTableIds  only the rows of these sheet tables (null = all rows)
     */
    public function export(ManagedTable $table, string $format, int|array|null $sourceTableIds = null): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new InvalidArgumentException('Unknown export format "'.$format.'".');
        }

        $types = $this->schema->existingTypes($table->name);
        $columns = array_values(array_unique(['id', ...array_keys($types)]));
        $directory = storage_path('app/exports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.$this->fileName($table, $format);

        match ($format) {
            'json' => $this->writeJson($path, $table, $columns, $types, $sourceTableIds),
            default => $this->writeSheet($format === 'csv' ? new CsvWriter : new XlsxWriter, $path, $table, $columns, $types, $sourceTableIds),
        };

        return $path;
    }

    public function fileName(ManagedTable $table, string $format): string
    {
        return $table->name.'-'.now()->format('Y-m-d-His').'.'.$format;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $types
     */
    private function writeSheet(CsvWriter|XlsxWriter $writer, string $path, ManagedTable $table, array $columns, array $types, int|array|null $sourceTableIds): void
    {
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($columns));

        foreach ($this->rows($table, $columns, $types, $sourceTableIds) as $row) {
            $writer->addRow(Row::fromValues(array_values($row)));
        }

        $writer->close();
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $types
     */
    private function writeJson(string $path, ManagedTable $table, array $columns, array $types, int|array|null $sourceTableIds): void
    {
        $handle = fopen($path, 'w');
        fwrite($handle, '[');
        $first = true;

        foreach ($this->rows($table, $columns, $types, $sourceTableIds) as $row) {
            fwrite($handle, ($first ? "\n  " : ",\n  ").json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $first = false;
        }

        fwrite($handle, $first ? ']' : "\n]\n");
        fclose($handle);
    }

    /**
     * Rows as column => value, numbers as numbers (so Excel and JSON treat them as numbers).
     *
     * @param  list<string>  $columns
     * @param  array<string, string>  $types
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(ManagedTable $table, array $columns, array $types, int|array|null $sourceTableIds): \Generator
    {
        $query = DB::table($table->name)->select($columns)->orderByRaw('CHAR_LENGTH(id), id');

        if ($sourceTableIds !== null) {
            $query->whereIn('id', SourceRecord::query()->whereIn('source_table_id', (array) $sourceTableIds)->pluck('id')->map(fn (int $id) => SourceRecord::codeFor($id))->all());
        }

        foreach ($query->lazy(500) as $row) {
            $values = [];

            foreach ($columns as $column) {
                $value = $row->{$column};
                $values[$column] = $value !== null && in_array($types[$column] ?? '', self::NUMERIC_TYPES, true) && is_numeric($value)
                    ? $value + 0
                    : $value;
            }

            yield $values;
        }
    }
}
