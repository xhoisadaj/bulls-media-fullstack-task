<?php

namespace App\Filament\Resources\StructureAlerts;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Filament\Resources\Mappings\MappingResource;
use App\Filament\Resources\SourceTables\SourceTableResource;
use App\Filament\Resources\StructureAlerts\Pages\ListStructureAlerts;
use App\Models\StructureAlert;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Changes to the sheets' layout and access problems: new or missing columns, tables not
 * found, mass removals blocked, fallback in use.
 */
class StructureAlertResource extends Resource
{
    protected static ?string $model = StructureAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Review';

    protected static ?string $navigationLabel = 'Alerts';

    protected static ?string $modelLabel = 'alert';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = StructureAlert::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('message')->wrap()->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('updated_at')->label('Last seen')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(AlertStatus::class)->default(AlertStatus::Open->value),
                SelectFilter::make('type')->options(AlertType::class),
            ])
            ->recordActions([
                Action::make('mapColumn')
                    ->label('Map this column')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn (StructureAlert $record) => $record->type === AlertType::ColumnAdded && $record->source_table_id && $record->status === AlertStatus::Open)
                    ->url(fn (StructureAlert $record) => ($mapping = $record->sourceTable?->mappings()->first())
                        ? MappingResource::getUrl('edit', ['record' => $mapping])
                        : MappingResource::getUrl('create', ['source_table' => $record->source_table_id])),
                Action::make('openTable')
                    ->label('Open table')
                    ->color('gray')
                    ->visible(fn (StructureAlert $record) => $record->source_table_id !== null)
                    ->url(fn (StructureAlert $record) => SourceTableResource::getUrl('edit', ['record' => $record->source_table_id])),
                Action::make('resolve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (StructureAlert $record) => $record->status === AlertStatus::Open)
                    ->action(fn (StructureAlert $record) => $record->update(['status' => AlertStatus::Resolved, 'resolved_at' => now()])),
                Action::make('dismiss')
                    ->color('gray')
                    ->visible(fn (StructureAlert $record) => $record->status === AlertStatus::Open)
                    ->action(fn (StructureAlert $record) => $record->update(['status' => AlertStatus::Dismissed, 'resolved_at' => now()])),
            ])
            ->toolbarActions([
                BulkAction::make('dismissSelected')
                    ->label('Dismiss selected')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $records->each->update(['status' => AlertStatus::Dismissed, 'resolved_at' => now()])),
            ])
            ->emptyStateHeading('No open alerts')
            ->emptyStateDescription('New columns, missing columns, tables that moved or vanished, and access problems show up here.');
    }

    public static function getPages(): array
    {
        return ['index' => ListStructureAlerts::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
