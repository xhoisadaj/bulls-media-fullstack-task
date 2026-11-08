<?php

namespace App\Filament\Resources\Mappings\Schemas;

use App\Models\ManagedTable;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Sync\SchemaManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
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
                ->description('Pick the sheet columns to save and the database column for each. Removing a column here only stops syncing it; the database column and its data stay.')
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
                                        $set('target_column', self::suggestTarget((string) SourceColumn::query()->find($state)?->header));
                                    }
                                })
                                ->columnSpan(4),
                            Select::make('target_column')
                                ->label('Database column')
                                ->helperText(fn (Get $get): ?string => self::targetTable($get, '../../') === null ? null : 'Pick a column of the table, or type a name to add a new one.')
                                ->options(fn (Get $get): array => self::targetOptions(self::targetTable($get, '../../'), $get('target_column')))
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search, Get $get): array => self::targetSearch(self::targetTable($get, '../../'), $search))
                                ->getOptionLabelUsing(fn ($value, Get $get): string => self::targetLabel(self::targetTable($get, '../../'), (string) $value))
                                ->required()
                                ->distinct()
                                ->live()
                                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                    if ($error = app(SchemaManager::class)->nameError((string) $value, 'Column')) {
                                        $fail($error);
                                    }
                                })
                                ->columnSpan(4),
                            Text::make(fn (Get $get): HtmlString => self::typeNote(self::targetTable($get, '../../'), $get('source_column_id'), $get('target_column')))
                                ->columnSpan(4),
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
     * The database name for a sheet header. It equals an existing column's name when the two match
     * ("Product Name" → product_name), so matching columns are picked automatically.
     */
    public static function suggestTarget(string $header): string
    {
        return app(SchemaManager::class)->suggestName($header);
    }

    /**
     * @return array<string, string>
     */
    public static function targetOptions(?string $table, mixed $current): array
    {
        $options = [];

        foreach ($table === null ? [] : app(SchemaManager::class)->targetColumns($table) as $column => $type) {
            $options[$column] = $column.' · '.$type;
        }

        if (filled($current) && ! isset($options[$current])) {
            $options[(string) $current] = self::targetLabel($table, (string) $current);
        }

        return $options;
    }

    /**
     * Existing columns matching the search, plus the typed text as a new column.
     *
     * @return array<string, string>
     */
    public static function targetSearch(?string $table, string $search): array
    {
        $manager = app(SchemaManager::class);
        $results = [];

        foreach ($table === null ? [] : $manager->targetColumns($table) as $column => $type) {
            if (str_contains($column, strtolower(trim($search)))) {
                $results[$column] = $column.' · '.$type;
            }
        }

        $new = $manager->suggestName($search);

        if (trim($search) !== '' && ! isset($results[$new]) && $manager->nameError($new) === null && ! in_array($new, SchemaManager::SYSTEM_COLUMNS, true)) {
            $results[$new] = '+ New column "'.$new.'"';
        }

        return $results;
    }

    public static function targetLabel(?string $table, string $value): string
    {
        $type = $table === null ? null : (app(SchemaManager::class)->targetColumns($table)[$value] ?? null);

        return $type !== null ? $value.' · '.$type : $value.' · new column';
    }

    /**
     * The sheet column's type, and a warning when it doesn't fit the chosen existing column.
     */
    public static function typeNote(?string $table, mixed $sourceColumnId, mixed $target): HtmlString
    {
        $type = SourceColumn::query()->find($sourceColumnId)?->type;

        if ($type === null) {
            return new HtmlString('');
        }

        $existing = $table === null || blank($target) ? null : (app(SchemaManager::class)->targetColumns($table)[$target] ?? null);
        $note = '<span class="ss-muted">Sheet type: '.e($type->getLabel()).'</span>';

        if ($existing !== null && ! app(SchemaManager::class)->fits($existing, $type)) {
            $note .= '<br><span class="ss-error">⚠ '.e($target).' is '.e($existing).': '.e($type->getLabel()).' values don\'t fit. Pick another column or a new one.</span>';
        } elseif (filled($target) && $table !== null && $existing === null) {
            $note .= '<br><span class="ss-muted">Will be added to '.e($table).'.</span>';
        }

        return new HtmlString('<div class="ss" style="padding-top:2rem">'.$note.'</div>');
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

            $items[(string) Str::uuid()] = ['source_column_id' => $column->id, 'target_column' => $name];
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
