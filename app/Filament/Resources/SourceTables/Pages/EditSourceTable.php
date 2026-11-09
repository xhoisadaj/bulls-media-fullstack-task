<?php

namespace App\Filament\Resources\SourceTables\Pages;

use App\Enums\ColumnType;
use App\Enums\EndRule;
use App\Enums\IdentityMode;
use App\Filament\Resources\Mappings\MappingResource;
use App\Filament\Resources\SourceTables\Schemas\SourceTableForm;
use App\Filament\Resources\SourceTables\SourceTableResource;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\Mapping;
use App\Models\SourceColumn;
use App\Models\SourceTable;
use App\Support\Access;
use App\Sync\Backfiller;
use App\Sync\Fingerprinter;
use App\Sync\TableSetup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * A saved sheet table, shown as the same 4 steps as "Add a sheet table" with everything that was
 * chosen filled in. Every step can be opened directly; nothing has to be clicked through again.
 */
class EditSourceTable extends EditRecord
{
    use EditRecord\Concerns\HasWizard;

    protected static string $resource = SourceTableResource::class;

    /** @var list<int> */
    private array $trackedBefore = [];

    /** @var array<int, array{type: string, compare: bool}> */
    private array $columnsBefore = [];

    private bool $identityChanged = false;

    public function getTitle(): string|Htmlable
    {
        /** @var SourceTable $record */
        $record = $this->getRecord();

        return $record->name;
    }

    public function getSubheading(): ?string
    {
        /** @var SourceTable $record */
        $record = $this->getRecord();

        return $record->tab->spreadsheet->name.' / '.$record->tab->name.' · headers at '.TableSetup::position($record->header_row, $record->start_column);
    }

    protected function hasSkippableSteps(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveTop')
                ->label('Save changes')
                ->visible(fn (): bool => Access::canEdit())
                ->action(fn () => $this->save()),
            DeleteAction::make(),
        ];
    }

    protected function getSteps(): array
    {
        return [
            Step::make('Tab')
                ->description('Which tab holds the table')
                ->schema([
                    Text::make(fn (SourceTable $record): HtmlString => new HtmlString(
                        '<div class="ss"><p class="ss-muted">Spreadsheet / tab</p><p class="ss-strong">'.e($record->tab->spreadsheet->name).' / '.e($record->tab->name).'</p></div>'
                    )),
                    Grid::make(2)->schema([
                        TextInput::make('name')->label('Name in the app')->required()->maxLength(255),
                        Toggle::make('is_active')->label('Include in sync')->inline(false),
                    ]),
                ]),

            Step::make('Table')
                ->description('Where the headers are')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('header_row')->label('Header row')->numeric()->minValue(1)->required()->live(onBlur: true),
                        TextInput::make('start_column')->label('Start column (A = 1)')->numeric()->minValue(1)->required()->live(onBlur: true),
                        Select::make('end_rule')->options(EndRule::class)->required()->live(),
                    ]),
                    TextInput::make('end_row')->label('Last row')->numeric()->minValue(1)
                        ->visible(fn (Get $get) => SourceTableForm::enumValue($get('end_rule')) === EndRule::FixedRow->value)
                        ->required(fn (Get $get) => SourceTableForm::enumValue($get('end_rule')) === EndRule::FixedRow->value),
                    View::make('filament.tab-preview')
                        ->viewData(fn (Get $get, SourceTable $record): array => $this->tabPreview($record, (int) $get('header_row'), (int) $get('start_column'))),
                ]),

            Step::make('Save to')
                ->description('The database table')
                ->schema([
                    Text::make(fn (SourceTable $record): HtmlString => $this->savedInto($record)),
                ]),

            Step::make('Columns')
                ->description('Names, types and options')
                ->schema([
                    Repeater::make('columns')
                        ->relationship('columns', fn ($query) => $query->orderBy('position'))
                        ->label('Columns of the sheet table')
                        ->helperText('Saved columns keep their database name and type; change them on the mapping ("Add or change columns"). Compare: on = an edit in this column shows the row as changed; off = it is saved, but edits to it alone don\'t count as a change.')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->table([
                            TableColumn::make('Sheet column'),
                            TableColumn::make('Saved into'),
                            TableColumn::make('Type'),
                            TableColumn::make('Compare'),
                        ])
                        ->schema([
                            TextInput::make('header')->label('Sheet column')->hiddenLabel()->disabled()->dehydrated(false),
                            Text::make(fn (?SourceColumn $record): HtmlString => self::savedColumn($record)),
                            Select::make('type')->label('Type')->hiddenLabel()->options(ColumnType::class)->required()
                                ->disabled(fn (?SourceColumn $record): bool => (bool) $record?->isMapped())
                                ->dehydrated(fn (?SourceColumn $record): bool => ! $record?->isMapped()),
                            Toggle::make('compare')->label('Compare')->hiddenLabel(),
                        ]),
                    ...SourceTableForm::identityFields(fn (?SourceTable $record) => $record?->columns()->pluck('header', 'id')->all() ?? [], 'key_column_ids'),
                ]),
        ];
    }

    /**
     * @return array{preview: ?array<string, mixed>, error: ?string, clickMode: string}
     */
    private function tabPreview(SourceTable $record, int $headerRow, int $startColumn): array
    {
        try {
            $preview = app(TableSetup::class)->preview($record->tab, $headerRow > 0 && $startColumn > 0 ? [$headerRow, $startColumn] : null);

            return ['preview' => $preview, 'error' => null, 'clickMode' => 'edit'];
        } catch (GoogleAccessException $e) {
            return ['preview' => null, 'error' => $e->getMessage(), 'clickMode' => 'edit'];
        }
    }

    private function savedInto(SourceTable $record): HtmlString
    {
        $mappings = $record->mappings()->with('managedTable', 'columns')->orderBy('id')->get();

        if ($mappings->isEmpty()) {
            return new HtmlString('<div class="ss"><p class="ss-muted">Not saved into a database table yet.</p><p><a class="ss-banner-link" href="'.e(MappingResource::getUrl('create', ['source_table' => $record->id])).'">Save into a table →</a></p></div>');
        }

        $rows = $mappings->map(fn (Mapping $m) => '<tr><td class="ss-mono ss-strong">'.e($m->managedTable->name).'</td><td>'
            .e($m->columns->pluck('target_column')->implode(', ')).'</td><td>'
            .($m->is_active ? '' : '<span class="ss-muted">paused</span> ')
            .'<a class="ss-banner-link" href="'.e(MappingResource::getUrl('edit', ['record' => $m])).'">Add or change columns →</a></td></tr>')->implode('');

        return new HtmlString(
            '<div class="ss ss-stack"><div class="ss-scroll"><table class="ss-table"><thead><tr><th>Database table</th><th>Columns</th><th></th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .'<p><a class="ss-banner-link" href="'.e(MappingResource::getUrl('create', ['source_table' => $record->id])).'">Save into another table →</a></p></div>'
        );
    }

    private static function savedColumn(?SourceColumn $column): HtmlString
    {
        $targets = $column?->mappingColumns()->with('mapping.managedTable')->get()
            ->map(fn ($c) => $c->mapping->managedTable->name.'.'.$c->target_column)->all() ?? [];

        return new HtmlString($targets === []
            ? '<span class="ss-null">not saved</span>'
            : '<span class="ss-mono">'.e(implode(', ', $targets)).'</span>');
    }

    protected function beforeSave(): void
    {
        $this->trackedBefore = $this->record->trackedColumns()->pluck('id')->all();
        $this->columnsBefore = $this->record->columns()->get()->mapWithKeys(fn (SourceColumn $c) => [$c->id => ['type' => $c->type->value, 'compare' => (bool) $c->compare]])->all();

        $data = $this->form->getState();
        $mode = IdentityMode::from($data['identity_mode'] instanceof IdentityMode ? $data['identity_mode']->value : $data['identity_mode']);
        $keys = array_map('intval', $data['key_column_ids'] ?? []);

        $this->identityChanged = $mode !== $this->record->identity_mode || $keys != ($this->record->key_column_ids ?? []);

        if ($this->identityChanged && $mode === IdentityMode::Key) {
            $this->assertKeyUnique($keys);
        }
    }

    protected function afterSave(): void
    {
        $columnsAfter = $this->record->columns()->get()->mapWithKeys(fn (SourceColumn $c) => [$c->id => ['type' => $c->type->value, 'compare' => (bool) $c->compare]])->all();

        if (! $this->identityChanged && $columnsAfter == $this->columnsBefore) {
            return;
        }

        $messages = app(Backfiller::class)->afterConfigChange($this->record->refresh(), $this->trackedBefore, refreshFromSheet: ! $this->identityChanged);
        Notification::make()->title('Sheet table updated')->body(implode(' ', $messages) ?: null)->success()->send();
    }

    /**
     * Checks the new key against the stored rows, so it can be switched without reading the sheet.
     *
     * @param  list<int>  $keyIds
     */
    private function assertKeyUnique(array $keyIds): void
    {
        $fingerprinter = app(Fingerprinter::class);
        $seen = [];

        foreach ($this->record->records()->whereNull('removed_at')->get() as $record) {
            $identity = $fingerprinter->identity($record->data, $keyIds);

            if ($identity === null) {
                throw ValidationException::withMessages(['data.key_column_ids' => 'Stored row '.$record->sheet_row.' has an empty value in the chosen key.']);
            }

            if (isset($seen[$identity])) {
                throw ValidationException::withMessages(['data.key_column_ids' => 'Key "'.$identity.'" is not unique (rows '.$seen[$identity].' and '.$record->sheet_row.').']);
            }

            $seen[$identity] = $record->sheet_row;
        }
    }
}
