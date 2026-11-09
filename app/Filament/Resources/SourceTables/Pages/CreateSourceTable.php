<?php

namespace App\Filament\Resources\SourceTables\Pages;

use App\Enums\ColumnType;
use App\Enums\EndRule;
use App\Enums\IdentityMode;
use App\Enums\TriggerType;
use App\Filament\Resources\ManagedTables\ManagedTableResource;
use App\Filament\Resources\SourceTables\Schemas\SourceTableForm;
use App\Filament\Resources\SourceTables\SourceTableResource;
use App\Google\A1;
use App\Google\Exceptions\GoogleAccessException;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\ManagedTable;
use App\Models\Mapping;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\Tab;
use App\Support\Access;
use App\Sync\SchemaManager;
use App\Sync\TableSetup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * "Add a sheet table", one flow from sheet to database: pick a tab → pick a detected table (or give
 * its position) → pick the database table → name and type each column once, choose identity and
 * review mode. Create sets everything up and starts the first sync.
 */
class CreateSourceTable extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = SourceTableResource::class;

    protected static ?string $title = 'Add a sheet table';

    public function mount(): void
    {
        parent::mount();

        if ($tab = request()->integer('tab')) {
            $this->form->fill(['spreadsheet_id' => Tab::query()->find($tab)?->spreadsheet_id, 'tab_id' => $tab, 'identity_mode' => IdentityMode::SystemId->value, 'apply_mode' => 'review', 'end_rule' => EndRule::FirstEmptyRow->value, 'target_mode' => ManagedTable::query()->exists() ? 'existing' : 'new']);
        }
    }

    protected function getSteps(): array
    {
        return [
            Step::make('Tab')
                ->description('Which tab holds the table')
                ->schema([
                    Select::make('spreadsheet_id')
                        ->label('Spreadsheet')
                        ->options(fn () => Access::spreadsheets()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('tab_id', null)),
                    Select::make('tab_id')
                        ->label('Tab')
                        ->options(fn (Get $get) => Tab::query()
                            ->where('spreadsheet_id', $get('spreadsheet_id'))
                            ->where('is_present', true)
                            ->orderBy('id')
                            ->pluck('name', 'id'))
                        ->disabled(fn (Get $get): bool => blank($get('spreadsheet_id')))
                        ->required()
                        ->live(),
                ]),

            Step::make('Table')
                ->description('Where the headers are')
                ->schema([
                    Radio::make('detected')
                        ->label('Tables found in this tab')
                        ->options(fn (Get $get) => $this->detectedOptions($get('tab_id')))
                        ->descriptions(fn (Get $get) => $this->detectedDescriptions($get('tab_id')))
                        ->required()
                        ->live(),
                    Grid::make(2)
                        ->visible(fn (Get $get) => $get('detected') === 'manual')
                        ->schema([
                            TextInput::make('manual_cell')
                                ->label('Header row starts at cell')
                                ->placeholder('H99')
                                ->regex('/^[A-Za-z]{1,3}[1-9]\d*$/')
                                ->required(fn (Get $get) => $get('detected') === 'manual')
                                ->live(onBlur: true),
                        ]),
                    View::make('filament.tab-preview')
                        ->viewData(fn (Get $get): array => $this->tabPreview($get('tab_id'), $get('detected'), $get('manual_cell'))),
                ])
                ->afterValidation(function (Get $get, Set $set) {
                    [$row, $column] = $this->chosenPosition($get('detected'), $get('manual_cell'));
                    $tab = Tab::query()->findOrFail($get('tab_id'));
                    $existing = SourceTable::query()->where('tab_id', $tab->id)->where('header_row', $row)->where('start_column', $column)->first();

                    // Already set up: open the saved setup instead of setting it up twice.
                    if ($existing !== null) {
                        Notification::make()
                            ->title('This table is already set up')
                            ->body('Here is its saved setup. Change anything there and save.')
                            ->info()
                            ->send();
                        $this->redirect(SourceTableResource::getUrl('edit', ['record' => $existing]), navigate: true);

                        throw new Halt;
                    }

                    try {
                        $columns = app(TableSetup::class)->columns($tab, $row, $column);
                    } catch (GoogleAccessException $e) {
                        throw ValidationException::withMessages(['data.detected' => $e->getMessage()]);
                    }

                    if (count($columns) === 0) {
                        throw ValidationException::withMessages(['data.detected' => 'No headers found at '.TableSetup::position($row, $column).'.']);
                    }

                    $set('header_row', $row);
                    $set('start_column', $column);
                    $set('columns', array_map(fn (array $c): array => $c + ['save' => true, 'target' => app(SchemaManager::class)->suggestName($c['header'])], $columns));
                    $set('new_table_name', $get('new_table_name') ?: app(SchemaManager::class)->suggestName($tab->spreadsheet->name));
                    $set('name', $get('name') ?: $tab->name.' at '.TableSetup::position($row, $column));
                }),

            Step::make('Save to')
                ->description('The database table')
                ->schema([
                    Radio::make('target_mode')
                        ->label('Save the rows into')
                        ->options(['new' => 'A new database table', 'existing' => 'An existing table (e.g. add a second file to sales)'])
                        ->required()
                        ->live(),
                    TextInput::make('new_table_name')
                        ->label('New table name')
                        ->placeholder('sales')
                        ->helperText('Lowercase letters, digits and _. Created when you click Create.')
                        ->visible(fn (Get $get): bool => $get('target_mode') === 'new')
                        ->required(fn (Get $get): bool => $get('target_mode') === 'new')
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            if ($error = app(SchemaManager::class)->nameError((string) $value, 'Table name')) {
                                $fail($error);
                            } elseif (app(SchemaManager::class)->isManaged((string) $value)) {
                                $fail('Table "'.$value.'" already exists. Choose "An existing table".');
                            }
                        }),
                    Select::make('managed_table_id')
                        ->label('Table')
                        ->options(fn () => Access::whereManagedTable(ManagedTable::query())->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Get $get): bool => $get('target_mode') === 'existing')
                        ->required(fn (Get $get): bool => $get('target_mode') === 'existing')
                        ->helperText('On the next step, each sheet column is matched to a column of this table by name; you pick the rest.'),
                ])
                ->afterValidation(function (Get $get, Set $set): void {
                    $existing = self::existingColumns($get('target_mode'), $get('managed_table_id'));
                    $columns = $get('columns') ?? [];

                    foreach ($columns as $key => $column) {
                        $match = app(SchemaManager::class)->suggestName((string) $column['header']);
                        $columns[$key]['target_pick'] = isset($existing[$match]) ? $match : null;

                        if (isset($existing[$match])) {
                            $columns[$key]['type'] = app(SchemaManager::class)->columnTypeOf($existing[$match])?->value ?? $column['type'];
                        }
                    }

                    $set('columns', $columns);
                }),

            Step::make('Columns')
                ->description('Names, types and options')
                ->schema([
                    Hidden::make('header_row')->required(),
                    Hidden::make('start_column')->required(),
                    Text::make(new HtmlString(
                        '<div class="ss ss-stack"><ul class="ss-list">'
                        .'<li><span class="ss-strong">Save:</span> untick to leave a column out of the database.</li>'
                        .'<li><span class="ss-strong">Database column / Type:</span> the name and type in the database table; the type is suggested from the data.</li>'
                        .'<li><span class="ss-strong">Compare:</span> should an edit in this column count as a change to the row? '
                        .'<span class="ss-strong">On</span> (normal): editing it shows the row as changed in the Review inbox. '
                        .'<span class="ss-strong">Off</span>: the column is ignored when looking for changes and when recognising rows, and its new value is saved the next time something else in the row changes. '
                        .'Turn it off for columns that change by themselves and would otherwise flag every row each day, e.g. a "last updated" time or a formula with TODAY() or NOW(). It is switched off automatically for those formulas.</li>'
                        .'</ul></div>'
                    )),
                    Text::make(fn (Get $get): ?HtmlString => self::existingOverview($get('target_mode'), $get('managed_table_id'), $get('columns') ?? []))
                        ->visible(fn (Get $get): bool => $get('target_mode') === 'existing'),
                    Repeater::make('columns')
                        ->label('Columns of the sheet table')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->table([
                            TableColumn::make('Sheet column'),
                            TableColumn::make('Save'),
                            TableColumn::make('Database column'),
                            TableColumn::make('Type'),
                            TableColumn::make('Compare'),
                            TableColumn::make('First values in the sheet'),
                        ])
                        ->schema([
                            TextInput::make('header')->label('Sheet column')->hiddenLabel()->disabled()->dehydrated(),
                            Toggle::make('save')->label('Save')->hiddenLabel()->default(true)->live(),
                            Group::make([
                                Select::make('target_pick')
                                    ->label('Database column')
                                    ->hiddenLabel()
                                    ->placeholder('Choose a column…')
                                    ->options(fn (Get $get): array => self::pickOptions($get('../../target_mode'), $get('../../managed_table_id')))
                                    ->visible(fn (Get $get): bool => $get('../../target_mode') === 'existing')
                                    ->required(fn (Get $get): bool => $get('../../target_mode') === 'existing' && (bool) $get('save'))
                                    ->disabled(fn (Get $get): bool => ! $get('save'))
                                    ->dehydrated()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $existing = self::existingColumns($get('../../target_mode'), $get('../../managed_table_id'))[$state] ?? null;

                                        if ($existing !== null) {
                                            $set('type', app(SchemaManager::class)->columnTypeOf($existing)?->value ?? $get('type'));
                                        } elseif ($state === self::NEW_COLUMN && blank($get('target'))) {
                                            $set('target', app(SchemaManager::class)->suggestName((string) $get('header')));
                                        }
                                    }),
                                TextInput::make('target')
                                    ->label('Database column')
                                    ->hiddenLabel()
                                    ->placeholder('new column name')
                                    ->visible(fn (Get $get): bool => $get('../../target_mode') !== 'existing' || $get('target_pick') === self::NEW_COLUMN)
                                    ->required(fn (Get $get): bool => (bool) $get('save'))
                                    ->disabled(fn (Get $get): bool => ! $get('save'))
                                    ->dehydrated()
                                    ->live(onBlur: true)
                                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                                        if ($get('save') && ($error = app(SchemaManager::class)->nameError((string) $value, 'Column'))) {
                                            $fail($error);
                                        } elseif ($get('save') && isset(self::existingColumns($get('../../target_mode'), $get('../../managed_table_id'))[trim((string) $value)])) {
                                            $fail('"'.trim((string) $value).'" already exists in the table: pick it from the list instead.');
                                        }
                                    }),
                            ]),
                            Select::make('type')->label('Type')->hiddenLabel()->options(ColumnType::class)->required()->live()
                                ->disabled(fn (Get $get): bool => self::pickedExisting($get('../../target_mode'), $get('../../managed_table_id'), $get('target_pick')))
                                ->dehydrated(),
                            Toggle::make('compare')->label('Compare')->hiddenLabel()
                                ->disabled(fn (Get $get): bool => ! $get('save'))
                                ->dehydrated(),
                            Text::make(fn (Get $get): HtmlString => self::sampleCells(
                                $get('samples') ?? [],
                                $get('warning'),
                                self::typeConflict($get('../../target_mode'), $get('../../managed_table_id'), $get('save') ? self::finalTarget($get('../../target_mode'), $get('target_pick'), $get('target')) : null, $get('type')),
                            )),
                        ]),
                    ...SourceTableForm::identityFields(
                        fn (Get $get) => collect($get('columns') ?? [])->pluck('header', 'header')->all(),
                        'key_headers',
                    ),
                    Select::make('end_rule')->options(EndRule::class)->default(EndRule::FirstEmptyRow)->required()->live(),
                    TextInput::make('end_row')->label('Last row')->numeric()->minValue(1)
                        ->visible(fn (Get $get) => SourceTableForm::enumValue($get('end_rule')) === EndRule::FixedRow->value),
                    TextInput::make('name')->label('Name in the app')->helperText('Just a label for this sheet table in the app.')->required()->maxLength(255),
                ]),
        ];
    }

    /**
     * Creates everything in one go: the sheet table and its columns, the database table (or picks the
     * existing one), the column mapping, the schema; then starts the first sync, which imports all rows.
     */
    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(Access::canAccessSpreadsheet(Tab::query()->find($data['tab_id'] ?? null)?->spreadsheet_id), 403);

        if (($data['target_mode'] ?? 'new') === 'existing') {
            abort_unless(Access::whereManagedTable(ManagedTable::query())->whereKey($data['managed_table_id'] ?? null)->exists(), 403);
        }

        $this->assertKeyIsUnique($data);

        $columns = array_values($data['columns'] ?? []);
        $saved = array_values(array_filter($columns, fn (array $c): bool => (bool) ($c['save'] ?? false)));
        $tableName = ($data['target_mode'] ?? 'new') === 'new'
            ? trim((string) ($data['new_table_name'] ?? ''))
            : (string) ManagedTable::query()->find($data['managed_table_id'] ?? null)?->name;

        if ($saved === []) {
            throw ValidationException::withMessages(['data.columns' => 'Tick "Save" for at least one column.']);
        }

        $mode = $data['target_mode'] ?? 'new';
        $plan = array_map(fn (array $c): array => ['target' => self::finalTarget($mode, $c['target_pick'] ?? null, $c['target'] ?? null), 'type' => ColumnType::from(SourceTableForm::enumValue($c['type']))], $saved);

        if ($errors = app(SchemaManager::class)->validatePlan($tableName, $plan)) {
            throw ValidationException::withMessages(['data.columns' => $errors]);
        }

        $mapping = DB::transaction(function () use ($data, $columns, $tableName, $mode) {
            $table = SourceTable::query()->create([
                'tab_id' => $data['tab_id'],
                'name' => $data['name'],
                'header_row' => (int) $data['header_row'],
                'start_column' => (int) $data['start_column'],
                'header_names' => array_column($columns, 'header'),
                'end_rule' => $data['end_rule'],
                'end_row' => $data['end_row'] ?? null,
                'identity_mode' => $data['identity_mode'],
                'apply_mode' => $data['apply_mode'],
            ]);

            $ids = [];

            foreach ($columns as $i => $column) {
                $ids[$column['header']] = SourceColumn::query()->create([
                    'source_table_id' => $table->id,
                    'header' => $column['header'],
                    'type' => $column['type'],
                    'compare' => (bool) ($column['compare'] ?? true),
                    'position' => $i + 1,
                ])->id;
            }

            $table->update(['key_column_ids' => array_values(array_map(fn ($h) => $ids[$h], $data['key_headers'] ?? []))]);
            Tab::query()->whereKey($data['tab_id'])->update(['is_active' => true]);

            $managed = ManagedTable::query()->firstOrCreate(['name' => $tableName], ['created_by' => auth()->id()]);
            $mapping = Mapping::query()->create(['source_table_id' => $table->id, 'managed_table_id' => $managed->id, 'is_active' => true]);

            foreach ($columns as $column) {
                if ($column['save'] ?? false) {
                    $mapping->columns()->create(['source_column_id' => $ids[$column['header']], 'target_column' => self::finalTarget($mode, $column['target_pick'] ?? null, $column['target'] ?? null)]);
                }
            }

            return $mapping;
        });

        // DDL commits implicitly in MySQL, so the schema is changed once the data is committed,
        // then the first sync starts.
        DB::afterCommit(function () use ($mapping): void {
            app(SchemaManager::class)->apply($mapping);
            SyncSpreadsheetJob::start($mapping->sourceTable->tab->spreadsheet, TriggerType::Manual);
        });

        return $mapping->sourceTable;
    }

    /**
     * The key must be unique and never empty in the current data. Checked on Create (the last
     * wizard step has no "next" to hook into).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertKeyIsUnique(array $data): void
    {
        if (SourceTableForm::enumValue($data['identity_mode'] ?? null) !== IdentityMode::Key->value) {
            return;
        }

        $types = collect($data['columns'] ?? [])->mapWithKeys(fn ($c) => [$c['header'] => SourceTableForm::enumValue($c['type'])])->all();
        $problems = app(TableSetup::class)->keyProblems(Tab::query()->findOrFail($data['tab_id']), (int) $data['header_row'], (int) $data['start_column'], $data['key_headers'] ?? [], $types);

        if ($problems !== []) {
            throw ValidationException::withMessages(['data.key_headers' => 'This key is not unique: '.implode(' ', $problems).' Combine columns or use another identity mode.']);
        }
    }

    protected function getRedirectUrl(): string
    {
        return ManagedTableResource::getUrl('browse', ['record' => $this->record->mappings()->firstOrFail()->managed_table_id]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Saved. Syncing the data from Google…';
    }

    /**
     * @return array<string, string>
     */
    private function detectedOptions(mixed $tabId): array
    {
        $tab = $tabId ? Tab::query()->find($tabId) : null;

        if ($tab === null) {
            return [];
        }

        try {
            $tables = app(TableSetup::class)->detect($tab);
        } catch (GoogleAccessException $e) {
            return ['manual' => 'Could not read the tab ('.$e->getMessage().'). Enter the position manually'];
        }

        $options = [];

        foreach ($tables as $table) {
            $setUp = SourceTable::query()->where('tab_id', $tab->id)->where('header_row', $table->headerRow + 1)->where('start_column', $table->startColumn + 1)->exists();
            $options['r'.($table->headerRow + 1).'c'.($table->startColumn + 1)] = 'Headers at '.A1::cell($table->headerRow, $table->startColumn).': '.implode(', ', array_filter($table->headers)).($setUp ? '  (already set up: opens its saved setup)' : '');
        }

        return $options + ['manual' => 'Somewhere else: enter the header cell'];
    }

    /**
     * @return array<string, string>
     */
    private function detectedDescriptions(mixed $tabId): array
    {
        $tab = $tabId ? Tab::query()->find($tabId) : null;

        if ($tab === null) {
            return [];
        }

        try {
            $tables = app(TableSetup::class)->detect($tab);
        } catch (GoogleAccessException) {
            return [];
        }

        $descriptions = [];

        foreach ($tables as $table) {
            $descriptions['r'.($table->headerRow + 1).'c'.($table->startColumn + 1)] = $table->dataRowCount.' row(s) until the first empty row. First row: '.implode(' | ', $table->sampleRows[0] ?? []);
        }

        return $descriptions;
    }

    /** The "+ New column" choice in the database column list of an existing table. */
    private const NEW_COLUMN = '__new__';

    /**
     * The database column a sheet column goes to: the picked column of an existing table, or the typed name.
     */
    private static function finalTarget(mixed $mode, mixed $pick, mixed $typed): string
    {
        return trim((string) ($mode === 'existing' && filled($pick) && $pick !== self::NEW_COLUMN ? $pick : $typed));
    }

    private static function pickedExisting(mixed $mode, mixed $managedTableId, mixed $pick): bool
    {
        return filled($pick) && $pick !== self::NEW_COLUMN && isset(self::existingColumns($mode, $managedTableId)[$pick]);
    }

    /**
     * The existing table's columns with their types, plus "+ New column".
     *
     * @return array<string, string>
     */
    private static function pickOptions(mixed $mode, mixed $managedTableId): array
    {
        $options = [];

        foreach (self::existingColumns($mode, $managedTableId) as $column => $type) {
            $options[$column] = $column.' · '.$type;
        }

        return $options + [self::NEW_COLUMN => '+ New column…'];
    }

    /**
     * Which columns of the existing table this file fills, which stay empty for its rows, and which it adds.
     *
     * @param  array<array-key, array<string, mixed>>  $columns
     */
    private static function existingOverview(mixed $mode, mixed $managedTableId, array $columns): ?HtmlString
    {
        $existing = self::existingColumns($mode, $managedTableId);
        $table = ManagedTable::query()->find($managedTableId)?->name;

        if ($table === null) {
            return null;
        }

        $filled = [];
        $added = [];
        $open = 0;

        foreach ($columns as $column) {
            if (! ($column['save'] ?? false)) {
                continue;
            }

            $target = self::finalTarget($mode, $column['target_pick'] ?? null, $column['target'] ?? null);

            if ($target === '') {
                $open++;
            } elseif (isset($existing[$target])) {
                $filled[$target] = $column['header'];
            } else {
                $added[] = $target;
            }
        }

        $chips = fn (array $items): string => $items === [] ? '<span class="ss-null">none</span>' : implode('', array_map(fn ($i) => '<span class="ss-chip ss-mono">'.e($i).'</span>', $items));
        $empty = array_keys(array_diff_key($existing, $filled));

        return new HtmlString(
            '<div class="ss ss-stack"><p class="ss-strong">Columns of '.e($table).'</p>'
            .'<div><span class="ss-muted">Filled by this file:</span> <div class="ss-chips">'.$chips(array_map(fn ($t, $h) => $t.' ← '.$h, array_keys($filled), $filled)).'</div></div>'
            .'<div><span class="ss-muted">Stay empty for this file\'s rows:</span> <div class="ss-chips">'.$chips($empty).'</div></div>'
            .'<div><span class="ss-muted">New columns this file adds:</span> <div class="ss-chips">'.$chips($added).'</div></div>'
            .($open > 0 ? '<p class="ss-error">'.$open.' column(s) below have no match in '.e($table).': pick a column, "+ New column", or untick Save.</p>' : '')
            .'</div>'
        );
    }

    /**
     * @return array<string, string> columns of the chosen existing table (name => MySQL type)
     */
    private static function existingColumns(mixed $mode, mixed $managedTableId): array
    {
        if ($mode !== 'existing') {
            return [];
        }

        $name = ManagedTable::query()->find($managedTableId)?->name;

        return $name === null ? [] : app(SchemaManager::class)->targetColumns($name);
    }

    /**
     * A warning when the column already exists in the chosen table with a type the values don't fit.
     */
    private static function typeConflict(mixed $mode, mixed $managedTableId, mixed $target, mixed $type): ?string
    {
        $existing = blank($target) ? null : (self::existingColumns($mode, $managedTableId)[trim((string) $target)] ?? null);
        $columnType = ColumnType::tryFrom(SourceTableForm::enumValue($type) ?? '');

        if ($existing === null || $columnType === null) {
            return null;
        }

        return app(SchemaManager::class)->fits($existing, $columnType)
            ? null
            : 'The table already has "'.trim((string) $target).'" as '.$existing.': '.$columnType->getLabel().' doesn\'t fit. Use another name or type.';
    }

    /**
     * The first values of a column as they appear in the sheet, plus any warning.
     *
     * @param  list<string>  $samples
     */
    private static function sampleCells(array $samples, ?string $warning, ?string $conflict = null): HtmlString
    {
        $cells = $samples === []
            ? '<span class="ss-null">no values in the first rows</span>'
            : implode('', array_map(fn (string $value) => '<span class="ss-chip ss-mono">'.e($value).'</span>', $samples));

        return new HtmlString(
            '<div class="ss"><div class="ss-chips">'.$cells.'</div>'
            .($warning ? '<p class="ss-error" style="margin-top:0.5rem">⚠ '.e($warning).'</p>' : '')
            .($conflict ? '<p class="ss-error" style="margin-top:0.5rem">⚠ '.e($conflict).'</p>' : '').'</div>'
        );
    }

    /**
     * Data for the spreadsheet preview under the table choices.
     *
     * @return array{preview: ?array<string, mixed>, error: ?string}
     */
    private function tabPreview(mixed $tabId, ?string $detected, ?string $manualCell): array
    {
        $tab = $tabId ? Tab::query()->find($tabId) : null;

        if ($tab === null) {
            return ['preview' => null, 'error' => null];
        }

        $hasCell = $detected !== null && ($detected !== 'manual' || preg_match('/^[A-Za-z]{1,3}[1-9]\d*$/', (string) $manualCell));

        try {
            return ['preview' => app(TableSetup::class)->preview($tab, $hasCell ? $this->chosenPosition($detected, $manualCell) : null), 'error' => null];
        } catch (GoogleAccessException $e) {
            return ['preview' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{0: int, 1: int} 1-based header row and start column
     */
    private function chosenPosition(?string $detected, ?string $manualCell): array
    {
        if ($detected !== null && preg_match('/^r(\d+)c(\d+)$/', $detected, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        preg_match('/^([A-Za-z]+)(\d+)$/', (string) $manualCell, $m);
        $column = 0;

        foreach (str_split(strtoupper($m[1] ?? 'A')) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return [(int) ($m[2] ?? 1), $column];
    }
}
