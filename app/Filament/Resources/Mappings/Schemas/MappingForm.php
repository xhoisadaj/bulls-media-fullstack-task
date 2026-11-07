<?php

namespace App\Filament\Resources\Mappings\Schemas;

use App\Models\ManagedTable;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Sync\SchemaManager;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * "This source table goes to that database table, with these columns."
 */
class MappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('From → to')
                ->columns(2)
                ->schema([
                    Select::make('source_table_id')
                        ->label('Source table')
                        ->options(fn () => SourceTable::query()->with('tab.spreadsheet')->get()->mapWithKeys(fn (SourceTable $t) => [$t->id => $t->label()]))
                        ->searchable()
                        ->required()
                        ->live()
                        ->disabledOn('edit'),
                    Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                    Radio::make('target_mode')
                        ->label('Target table')
                        ->options(['existing' => 'An existing table created by the sync', 'new' => 'Create a new table'])
                        ->default(fn () => ManagedTable::query()->exists() ? 'existing' : 'new')
                        ->live()
                        ->dehydrated(false)
                        ->hiddenOn('edit'),
                    Select::make('managed_table_id')
                        ->label('Table')
                        ->options(fn () => ManagedTable::query()->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Get $get, string $operation) => $operation === 'edit' || $get('target_mode') === 'existing')
                        ->required(fn (Get $get, string $operation) => $operation === 'create' && $get('target_mode') === 'existing')
                        ->disabledOn('edit'),
                    TextInput::make('new_table_name')
                        ->label('New table name')
                        ->placeholder('sales')
                        ->helperText('Lowercase letters, digits and _. Created when you save.')
                        ->visible(fn (Get $get, string $operation) => $operation === 'create' && $get('target_mode') === 'new')
                        ->required(fn (Get $get, string $operation) => $operation === 'create' && $get('target_mode') === 'new')
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            if ($error = app(SchemaManager::class)->nameError((string) $value, 'Table name')) {
                                $fail($error);
                            }
                        })
                        ->dehydrated(false),
                ]),

            Section::make('Columns')
                ->description('Pick the sheet columns to save and their database names. Removing a column here only stops syncing it; the database column and its data stay.')
                ->schema([
                    Repeater::make('columns')
                        ->relationship()
                        ->hiddenLabel()
                        ->columns(12)
                        ->defaultItems(0)
                        ->addActionLabel('Add column')
                        ->schema([
                            Select::make('source_column_id')
                                ->label('Sheet column')
                                ->options(fn (Get $get) => self::columnOptions($get('../../source_table_id')))
                                ->required()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->live()
                                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                    if ($state && blank($get('target_column'))) {
                                        $set('target_column', app(SchemaManager::class)->suggestName((string) SourceColumn::query()->find($state)?->header));
                                    }
                                })
                                ->columnSpan(5),
                            TextInput::make('target_column')
                                ->label('Database column')
                                ->required()
                                ->distinct()
                                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                    if ($error = app(SchemaManager::class)->nameError((string) $value, 'Column')) {
                                        $fail($error);
                                    }
                                })
                                ->columnSpan(4),
                            Text::make(fn (Get $get) => ($type = SourceColumn::query()->find($get('source_column_id'))?->type) ? 'Type: '.$type->getLabel() : '')
                                ->columnSpan(3),
                        ]),
                ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function columnOptions(mixed $sourceTableId): array
    {
        if (! $sourceTableId) {
            return [];
        }

        return SourceColumn::query()
            ->where('source_table_id', $sourceTableId)
            ->where('header', '!=', '_sync_id')
            ->orderBy('position')
            ->get()
            ->mapWithKeys(fn (SourceColumn $c) => [$c->id => $c->header.($c->is_present ? '' : ' (missing from sheet)')])
            ->all();
    }
}
