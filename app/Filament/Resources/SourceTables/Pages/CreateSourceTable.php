<?php

namespace App\Filament\Resources\SourceTables\Pages;

use App\Enums\ColumnType;
use App\Enums\EndRule;
use App\Enums\IdentityMode;
use App\Filament\Resources\Mappings\MappingResource;
use App\Filament\Resources\SourceTables\Schemas\SourceTableForm;
use App\Filament\Resources\SourceTables\SourceTableResource;
use App\Google\A1;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Models\Tab;
use App\Sync\TableSetup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * "Set up a table": pick a tab → pick a detected table (or give its position) → confirm
 * column types, identity and review mode.
 */
class CreateSourceTable extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = SourceTableResource::class;

    protected static ?string $title = 'Set up a table';

    public function mount(): void
    {
        parent::mount();

        if ($tab = request()->integer('tab')) {
            $this->form->fill(['tab_id' => $tab, 'identity_mode' => IdentityMode::Content->value, 'apply_mode' => 'review', 'end_rule' => EndRule::FirstEmptyRow->value]);
        }
    }

    protected function getSteps(): array
    {
        return [
            Step::make('Tab')
                ->description('Which tab holds the table')
                ->schema([
                    Select::make('tab_id')
                        ->label('Tab')
                        ->options(fn () => Tab::query()->where('is_present', true)->with('spreadsheet')->get()
                            ->mapWithKeys(fn (Tab $t) => [$t->id => $t->spreadsheet->name.' / '.$t->name]))
                        ->searchable()
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
                    $set('columns', $columns);
                    $set('name', $get('name') ?: $tab->name.' at '.TableSetup::position($row, $column));
                }),

            Step::make('Columns & identity')
                ->description('Types, keys and review')
                ->schema([
                    Hidden::make('header_row')->required(),
                    Hidden::make('start_column')->required(),
                    TextInput::make('name')->required()->maxLength(255),
                    Repeater::make('columns')
                        ->label('Columns (types are suggested from the data; confirm them)')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columns(12)
                        ->schema([
                            TextInput::make('header')->disabled()->dehydrated()->columnSpan(3),
                            Select::make('type')->options(ColumnType::class)->required()->columnSpan(3),
                            Toggle::make('compare')->label('Compare')->inline(false)->columnSpan(2)
                                ->hintIcon(Heroicon::OutlinedInformationCircle, 'On: a change in this column marks the row as modified. Off: the value is stored but ignored when looking for changes.'),
                            Text::make(fn (Get $get): HtmlString => self::sampleCells($get('samples') ?? [], $get('warning')))
                                ->columnSpan(4),
                        ]),
                    ...SourceTableForm::identityFields(
                        fn (Get $get) => collect($get('columns') ?? [])->pluck('header', 'header')->all(),
                        'key_headers',
                    ),
                    Select::make('end_rule')->options(EndRule::class)->default(EndRule::FirstEmptyRow)->required()->live(),
                    TextInput::make('end_row')->label('Last row')->numeric()->minValue(1)
                        ->visible(fn (Get $get) => SourceTableForm::enumValue($get('end_rule')) === EndRule::FixedRow->value),
                ]),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $this->assertKeyIsUnique($data);

        return DB::transaction(function () use ($data) {
            $columns = $data['columns'] ?? [];

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

            foreach (array_values($columns) as $i => $column) {
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

            return $table;
        });
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
        return MappingResource::getUrl('create', ['source_table' => $this->record->id]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Table saved. Now map its columns to a database table.';
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
            $options['r'.($table->headerRow + 1).'c'.($table->startColumn + 1)] = 'Headers at '.A1::cell($table->headerRow, $table->startColumn).': '.implode(', ', array_filter($table->headers));
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

    /**
     * The first values of a column as they appear in the sheet, plus any warning.
     *
     * @param  list<string>  $samples
     */
    private static function sampleCells(array $samples, ?string $warning): HtmlString
    {
        $cells = $samples === []
            ? '<span class="ss-null">no values in the first rows</span>'
            : implode('', array_map(fn (string $value) => '<span class="ss-chip ss-mono">'.e($value).'</span>', $samples));

        return new HtmlString(
            '<div class="ss"><p class="ss-muted">First values in the sheet</p><div class="ss-chips" style="margin-top:0.35rem">'.$cells.'</div>'
            .($warning ? '<p class="ss-error" style="margin-top:0.5rem">⚠ '.e($warning).'</p>' : '').'</div>'
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
