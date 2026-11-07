<?php

namespace App\Filament\Resources\SourceTables\RelationManagers;

use App\Enums\ColumnType;
use App\Models\SourceColumn;
use App\Sync\Backfiller;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ColumnsRelationManager extends RelationManager
{
    protected static string $relationship = 'columns';

    protected static ?string $title = 'Columns';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('header')
                ->required()
                ->helperText('If the column was renamed in the sheet, type the new header here: tracking continues with the same mapping.'),
            Select::make('type')
                ->options(ColumnType::class)
                ->required()
                ->disabled(fn (?SourceColumn $record) => $record?->isMapped() ?? false)
                ->helperText(fn (?SourceColumn $record) => $record?->isMapped() ? 'Mapped columns keep their type (changing it could lose data). Map to a new column instead.' : null),
            Toggle::make('compare')->label('Use to detect changes'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('header')
            ->columns([
                TextColumn::make('position')->label('#'),
                TextColumn::make('header')->weight('semibold'),
                TextColumn::make('type')->badge()->color('gray'),
                IconColumn::make('compare')->boolean(),
                IconColumn::make('is_present')->label('In sheet')->boolean(),
                TextColumn::make('mapped')->label('Mapped to')
                    ->state(fn (SourceColumn $record) => $record->mappingColumns()->with('mapping.managedTable')->get()
                        ->map(fn ($c) => $c->mapping->managedTable->name.'.'.$c->target_column)->implode(', ') ?: '—'),
            ])
            ->recordActions([
                EditAction::make()
                    ->before(function (SourceColumn $record) {
                        $this->trackedBefore = $record->sourceTable->trackedColumns()->pluck('id')->all();
                    })
                    ->after(function (SourceColumn $record) {
                        $messages = app(Backfiller::class)->afterConfigChange($record->sourceTable, $this->trackedBefore ?? []);
                        Notification::make()->title('Column updated')->body(implode(' ', $messages) ?: null)->success()->send();
                    }),
            ])
            ->paginated(false);
    }

    /** @var list<int>|null */
    public ?array $trackedBefore = null;
}
