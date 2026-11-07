<?php

namespace App\Filament\Resources\SyncRuns;

use App\Enums\SyncStatus;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Models\Spreadsheet;
use App\Models\SyncRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Activity';

    protected static ?string $navigationLabel = 'Sync history';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('spreadsheet'))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('spreadsheet.name')->label('Spreadsheet')->weight('semibold'),
                TextColumn::make('status')->badge(),
                TextColumn::make('trigger')->badge()->color('gray'),
                TextColumn::make('credential_used')->label('Via')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('summary')->label('Result')->wrap()
                    ->state(fn (SyncRun $record) => self::summary($record)),
                TextColumn::make('errors_count')->label('Notes')
                    ->state(fn (SyncRun $record) => count($record->errors ?? []) ?: null)->placeholder('—'),
                TextColumn::make('started_at')->label('Started')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('duration')
                    ->state(fn (SyncRun $record) => $record->finished_at ? round($record->started_at->diffInMilliseconds($record->finished_at) / 1000, 1).'s' : '…'),
            ])
            ->filters([
                SelectFilter::make('spreadsheet_id')->label('Spreadsheet')->options(fn () => Spreadsheet::query()->pluck('name', 'id')),
                SelectFilter::make('status')->options(SyncStatus::class),
            ])
            ->recordActions([
                Action::make('notes')
                    ->label('Details')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->visible(fn (SyncRun $record) => ($record->errors ?? []) !== [] || ($record->counts ?? []) !== [])
                    ->modalHeading(fn (SyncRun $record) => 'Sync of "'.$record->spreadsheet->name.'"')
                    ->modalContent(fn (SyncRun $record) => view('filament.sync-run-detail', ['run' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    public static function summary(SyncRun $run): string
    {
        $counts = $run->counts ?? [];

        if ($counts === []) {
            return '—';
        }

        return collect(['new', 'modified', 'removed', 'restored', 'unchanged', 'auto_applied', 'pending_created'])
            ->filter(fn ($key) => ($counts[$key] ?? 0) > 0)
            ->map(fn ($key) => $counts[$key].' '.str_replace(['auto_applied', 'pending_created'], ['applied', 'queued'], $key))
            ->implode(' · ') ?: 'no rows';
    }

    public static function getPages(): array
    {
        return ['index' => ListSyncRuns::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
