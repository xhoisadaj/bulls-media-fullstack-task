<?php

namespace App\Filament\Resources\Mappings\Schemas;

use App\Enums\ColumnType;
use App\Filament\Resources\SourceTables\Schemas\SourceTableForm;
use App\Models\ManagedTable;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Sync\SchemaManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * "This source table goes to that database table, with these columns."
 */
class MappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('From → to')
                ->columnSpanFull()
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
                        ->live()
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
                ->columnSpanFull()
                ->description('Each row saves one sheet column into a database column: type any name and pick the type (the recommended one is preselected). Removing a row only stops syncing it; the database column and its data stay. To add an empty column that no sheet column fills, use Target tables → Add column.')
                ->schema([
                    Text::make(fn (Get $get): ?HtmlString => self::overview(self::targetTable($get), $get('source_table_id'), $get('columns') ?? []))
                        ->visible(fn (Get $get): bool => self::targetTable($get) !== null),
                    Actions::make([
                        Action::make('mapMatching')
                            ->label('Map matching columns')
                            ->icon(Heroicon::OutlinedArrowsRightLeft)
                            ->color('gray')
                            ->action(function (Get $get, Set $set): void {
                                $added = self::mapMatching(self::targetTable($get), $get('source_table_id'), $get('columns') ?? []);
                                $set('columns', $added['columns']);

                                Notification::make()
                                    ->title($added['count'] === 0 ? 'No more matching columns' : $added['count'].' column(s) matched by name')
                                    ->body($added['count'] === 0 ? 'Map the rest by hand, or pick "New column".' : 'Check them, then save.')
                                    ->send();
                            }),
                    ])->visible(fn (Get $get): bool => self::targetTable($get) !== null && filled($get('source_table_id'))),
                    Repeater::make('columns')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Sheet column')->markAsRequired(),
                            TableColumn::make('Database column')->markAsRequired(),
                            TableColumn::make('Type')->markAsRequired(),
                            TableColumn::make('Notes'),
                        ])
                        ->defaultItems(0)
                        ->addActionLabel('Map a sheet column')
                        ->schema([
                            Select::make('source_column_id')
                                ->label('Sheet column')
                                ->hiddenLabel()
                                ->options(fn (Get $get) => self::columnOptions($get('../../source_table_id')))
                                ->required()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->live()
                                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                    $column = SourceColumn::query()->find($state);

                                    if ($column === null) {
                                        return;
                                    }

                                    if (blank($get('target_column'))) {
                                        $set('target_column', app(SchemaManager::class)->suggestName($column->header));
                                    }

                                    $set('target_type', self::recommendedType(self::targetTable($get, '../../'), $get('target_column'), $column)?->value);
                                }),
                            TextInput::make('target_column')
                                ->label('Database column')
                                ->hiddenLabel()
                                ->placeholder('any name, e.g. product_name')
                                ->datalist(fn (Get $get): array => array_keys(self::existingColumns(self::targetTable($get, '../../'))))
                                ->required()
                                ->distinct()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                    $existing = self::existingType(self::targetTable($get, '../../'), $state);

                                    if ($existing !== null) {
                                        $set('target_type', app(SchemaManager::class)->columnTypeOf($existing)?->value);
                                    }
                                })
                                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                    if ($error = app(SchemaManager::class)->nameError((string) $value, 'Column')) {
                                        $fail($error);
                                    }
                                }),
                            Select::make('target_type')
                                ->label('Type')
                                ->hiddenLabel()
                                ->options(ColumnType::class)
                                ->required()
                                ->live()
                                ->afterStateHydrated(function (Select $component, $state, Get $get): void {
                                    if (blank($state) && ($column = SourceColumn::query()->find($get('source_column_id'))) !== null) {
                                        $component->state(self::recommendedType(self::targetTable($get, '../../'), $get('target_column'), $column)?->value);
                                    }
                                })
                                ->disabled(fn (Get $get): bool => self::existingType(self::targetTable($get, '../../'), $get('target_column')) !== null)
                                ->dehydrated(),
                            Text::make(fn (Get $get): HtmlString => self::typeNote(self::targetTable($get, '../../'), $get('source_column_id'), $get('target_column'), $get('target_type'))),
                        ]),
                ]),
        ]);
    }

    /**
     * The existing table this mapping writes to, or null for a new table (or none chosen yet).
     */
    public static function targetTable(Get $get, string $prefix = ''): ?string
    {
        if ($get($prefix.'target_mode') === 'new') {
            return null;
        }

        $name = ManagedTable::query()->find($get($prefix.'managed_table_id'))?->name;

        return $name !== null && DatabaseSchema::hasTable($name) ? $name : null;
    }

    /**
     * @return array<string, string> column name => MySQL type of the existing target table
     */
    public static function existingColumns(?string $table): array
    {
        return $table === null ? [] : app(SchemaManager::class)->targetColumns($table);
    }

    public static function existingType(?string $table, mixed $column): ?string
    {
        return blank($column) ? null : (self::existingColumns($table)[(string) $column] ?? null);
    }

    /**
     * The type to preselect: the existing column's type, else the sheet column's type.
     */
    public static function recommendedType(?string $table, mixed $target, SourceColumn $column): ?ColumnType
    {
        $existing = self::existingType($table, $target);

        return $existing !== null ? app(SchemaManager::class)->columnTypeOf($existing) : $column->type;
    }

    public static function typeHint(?string $table, mixed $sourceColumnId, mixed $target): ?string
    {
        if (($existing = self::existingType($table, $target)) !== null) {
            return 'Set by the existing column ('.$existing.').';
        }

        $recommended = SourceColumn::query()->find($sourceColumnId)?->type;

        return $recommended === null ? null : 'Recommended: '.$recommended->getLabel();
    }

    /**
     * What happens to the values: a new column is added, and a warning when sheet values need converting.
     */
    public static function typeNote(?string $table, mixed $sourceColumnId, mixed $target, mixed $chosen): HtmlString
    {
        $sheetType = SourceColumn::query()->find($sourceColumnId)?->type;
        $type = ColumnType::tryFrom(SourceTableForm::enumValue($chosen) ?? '');

        if ($sheetType === null || blank($target)) {
            return new HtmlString('');
        }

        $notes = ['<span class="ss-muted">'.e((string) self::typeHint($table, $sourceColumnId, $target)).'</span>'];

        if ($table !== null && self::existingType($table, $target) === null) {
            $notes[] = '<span class="ss-muted">New column, added to '.e($table).'.</span>';
        }

        if ($type !== null && $type !== $sheetType) {
            $notes[] = '<span class="ss-error">⚠ The sheet column is '.e($sheetType->getLabel()).'. Values are converted to '.e($type->getLabel()).'; values that don\'t convert are saved empty.</span>';
        }

        return new HtmlString('<div class="ss">'.implode('<br>', $notes).'</div>');
    }

    /**
     * Which column of the existing table gets which sheet column, and which stay empty for this file.
     *
     * @param  array<array-key, array<string, mixed>>  $items
     */
    public static function overview(?string $table, mixed $sourceTableId, array $items): ?HtmlString
    {
        if ($table === null) {
            return null;
        }

        $headers = SourceColumn::query()->where('source_table_id', $sourceTableId)->pluck('header', 'id');
        $bySource = [];

        foreach ($items as $item) {
            if (filled($item['target_column'] ?? null)) {
                $bySource[(string) $item['target_column']] = $headers[$item['source_column_id'] ?? 0] ?? '?';
            }
        }

        $existing = app(SchemaManager::class)->targetColumns($table);
        $rows = '';

        foreach ($existing as $column => $type) {
            $from = isset($bySource[$column])
                ? '← '.e($bySource[$column])
                : '<span class="ss-null">not filled by this file (stays empty for its rows)</span>';
            $rows .= '<tr><td class="ss-mono">'.e($column).' <span class="ss-muted">'.e($type).'</span></td><td>'.$from.'</td></tr>';
        }

        foreach (array_diff_key($bySource, $existing) as $column => $header) {
            $rows .= '<tr><td class="ss-mono">'.e($column).' <span class="ss-new">new</span></td><td>← '.e($header).'</td></tr>';
        }

        return new HtmlString(
            '<div class="ss ss-stack"><p class="ss-strong">Columns of '.e($table).'</p>'
            .'<div class="ss-scroll"><table class="ss-table"><thead><tr><th>Database column</th><th>Filled from this file</th></tr></thead><tbody>'
            .($rows ?: '<tr><td colspan="2" class="ss-null">The table has no columns yet.</td></tr>')
            .'</tbody></table></div></div>'
        );
    }

    /**
     * Adds a mapping row for every unmapped sheet column whose name matches an unused column of the table.
     *
     * @param  array<array-key, array<string, mixed>>  $items
     * @return array{columns: array<array-key, array<string, mixed>>, count: int}
     */
    public static function mapMatching(?string $table, mixed $sourceTableId, array $items): array
    {
        $existing = $table === null ? [] : app(SchemaManager::class)->targetColumns($table);
        $usedSources = array_map('intval', array_filter(array_column($items, 'source_column_id')));
        $usedTargets = array_filter(array_column($items, 'target_column'));
        $count = 0;

        foreach (SourceColumn::query()->where('source_table_id', $sourceTableId)->orderBy('position')->get() as $column) {
            $name = app(SchemaManager::class)->suggestName($column->header);

            if (in_array($column->id, $usedSources, true) || ! isset($existing[$name]) || in_array($name, $usedTargets, true)) {
                continue;
            }

            $items[(string) Str::uuid()] = [
                'source_column_id' => $column->id,
                'target_column' => $name,
                'target_type' => app(SchemaManager::class)->columnTypeOf($existing[$name])?->value,
            ];
            $usedTargets[] = $name;
            $count++;
        }

        return ['columns' => $items, 'count' => $count];
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
            ->orderBy('position')
            ->get()
            ->mapWithKeys(fn (SourceColumn $c) => [$c->id => $c->header.($c->is_present ? '' : ' (missing from sheet)')])
            ->all();
    }
}
