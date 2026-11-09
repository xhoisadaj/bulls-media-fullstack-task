<?php

namespace App\Filament\Support;

use App\Exports\TableExporter;
use App\Models\ManagedTable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * "Export ▾ Excel / CSV / JSON" for a target table, optionally only one sheet table's rows.
 */
final class ExportActions
{
    /**
     * @param  Closure(Model): ?array{0: ManagedTable, 1: ?int}  $resolve  the table to export and the sheet table filter, for a record
     */
    public static function group(Closure $resolve, ?Closure $visible = null): ActionGroup
    {
        return ActionGroup::make(array_map(
            fn (string $format, string $label) => Action::make('export_'.$format)
                ->label($label)
                ->action(function (Model $record) use ($resolve, $format) {
                    [$table, $sourceTableId] = $resolve($record);

                    return response()->download(app(TableExporter::class)->export($table, $format, $sourceTableId))->deleteFileAfterSend();
                }),
            array_keys(TableExporter::FORMATS),
            TableExporter::FORMATS,
        ))
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->link()
            ->visible($visible ?? true);
    }
}
