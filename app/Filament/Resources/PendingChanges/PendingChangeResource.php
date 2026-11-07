<?php

namespace App\Filament\Resources\PendingChanges;

use App\Enums\ChangeStatus;
use App\Enums\ChangeType;
use App\Filament\Resources\PendingChanges\Pages\ListPendingChanges;
use App\Models\PendingChange;
use App\Models\SourceTable;
use App\Sync\ChangeApplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * The review inbox: every detected change, with what changed field by field.
 */
class PendingChangeResource extends Resource
{
    protected static ?string $model = PendingChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Review';

    protected static ?string $navigationLabel = 'Review inbox';

    protected static ?string $modelLabel = 'change';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'pending-changes';

    public static function getNavigationBadge(): ?string
    {
        $count = PendingChange::query()->pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sourceTable.columns', 'sourceTable.tab.spreadsheet', 'decider'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('sourceTable.name')->label('Table')
                    ->description(fn (PendingChange $record) => $record->sourceTable->tab->spreadsheet->name.' / '.$record->sourceTable->tab->name),
                TextColumn::make('sheet_row')->label('Row')->numeric()->sortable(),
                ViewColumn::make('diff')->label('What changed')->view('filament.change-diff'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label('Detected')->since()->sortable(),
                TextColumn::make('decider.name')->label('Decided by')->placeholder(fn (PendingChange $record) => $record->status === ChangeStatus::Approved ? 'Auto' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ChangeStatus::class)->default(ChangeStatus::Pending->value),
                SelectFilter::make('type')->options(ChangeType::class),
                SelectFilter::make('source_table_id')->label('Table')
                    ->options(fn () => SourceTable::query()->with('tab.spreadsheet')->get()->mapWithKeys(fn (SourceTable $t) => [$t->id => $t->label()])),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Details')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (PendingChange $record) => ucfirst($record->type->value).' row'.($record->sheet_row ? ' (sheet row '.$record->sheet_row.')' : ''))
                    ->modalContent(fn (PendingChange $record) => view('filament.change-detail', ['change' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (PendingChange $record) => $record->status === ChangeStatus::Pending)
                    ->action(fn (PendingChange $record) => self::decide(collect([$record]), approve: true)),
                Action::make('reject')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (PendingChange $record) => $record->status === ChangeStatus::Pending)
                    ->requiresConfirmation()
                    ->modalDescription(fn (PendingChange $record) => $record->type === ChangeType::Removed
                        ? 'The row stays in the database, and its removal won\'t be raised again unless the row comes back and disappears again.'
                        : 'This exact version won\'t be raised again. A new edit of the row will create a new change.')
                    ->action(fn (PendingChange $record) => self::decide(collect([$record]), approve: false)),
            ])
            ->toolbarActions([
                BulkAction::make('approveSelected')
                    ->label('Approve selected')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => self::decide($records, approve: true)),
                BulkAction::make('rejectSelected')
                    ->label('Reject selected')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => self::decide($records, approve: false)),
            ])
            ->emptyStateHeading('Nothing to review')
            ->emptyStateDescription('Changes found by the sync appear here. Approved ones are written to the database.');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PendingChange>  $changes
     */
    public static function decide(\Illuminate\Support\Collection $changes, bool $approve): void
    {
        $applier = app(ChangeApplier::class);
        $user = auth()->user();
        $done = 0;
        $skipped = 0;

        foreach ($changes as $change) {
            $ok = $approve ? $applier->approve($change, $user) : $applier->reject($change, $user);
            $ok ? $done++ : $skipped++;
        }

        Notification::make()
            ->title(($approve ? 'Approved ' : 'Rejected ').$done.' change(s)')
            ->body($skipped > 0 ? $skipped.' were no longer pending (already decided or superseded by a newer sync).' : null)
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return ['index' => ListPendingChanges::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
