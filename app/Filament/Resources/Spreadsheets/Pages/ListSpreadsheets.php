<?php

namespace App\Filament\Resources\Spreadsheets\Pages;

use App\Demo\DemoData;
use App\Enums\TriggerType;
use App\Filament\Resources\Spreadsheets\SpreadsheetResource;
use App\Google\A1;
use App\Google\Contracts\SheetsGateway;
use App\Google\Exceptions\GoogleAccessException;
use App\Google\SpreadsheetImporter;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\GoogleSetting;
use App\Models\Spreadsheet;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ListSpreadsheets extends ListRecords
{
    protected static string $resource = SpreadsheetResource::class;

    private static function canListFiles(): bool
    {
        $settings = GoogleSetting::current();

        return $settings->secret('refresh_token') !== null;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('loadDemo')
                    // Creates and drops the demo_ tables (DDL): no transaction.
                    ->databaseTransaction(false)
                    ->label(fn (): string => DemoData::isLoaded() ? 'Reload demo data' : 'Load demo data')
                    ->icon(Heroicon::OutlinedBeaker)
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => DemoData::isLoaded() ? 'Reload the demo data' : 'Load the demo data')
                    ->modalDescription('Adds 3 example spreadsheets (Shop London, Shop Paris, Online Orders) read from JSON files, no Google account needed, synced into demo_sales, demo_customers and demo_returns. Your own spreadsheets and tables are not touched. Reloading starts the demo over.')
                    ->authorize(fn (): bool => Access::isAdmin())
                    ->action(function () {
                        $results = app(DemoData::class)->load();
                        Notification::make()->title('Demo data loaded')->body(count($results).' demo spreadsheets synced into demo_sales, demo_customers and demo_returns.')->success()->send();
                    }),
                Action::make('removeDemo')
                    // Creates and drops the demo_ tables (DDL): no transaction.
                    ->databaseTransaction(false)
                    ->label('Remove demo data')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (): bool => DemoData::isLoaded())
                    ->requiresConfirmation()
                    ->modalDescription('Deletes the 3 demo spreadsheets with their sheet tables, mappings, changes and history, and drops the demo_ tables. Your own spreadsheets and tables are not touched.')
                    ->authorize(fn (): bool => Access::isAdmin())
                    ->action(function () {
                        $removed = app(DemoData::class)->remove();
                        Notification::make()->title('Demo data removed')->body('Removed '.$removed['spreadsheets'].' spreadsheet(s) and the tables '.(implode(', ', $removed['tables']) ?: 'none').'.')->success()->send();
                    }),
            ])
                ->label('Demo data')
                ->icon(Heroicon::OutlinedBeaker)
                ->color('gray')
                ->button()
                ->visible(fn (): bool => Access::isAdmin()),
            Action::make('syncAll')
                ->label('Sync all')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => Access::canEdit())
                ->authorize(fn (): bool => Access::canEdit())
                ->action(function () {
                    $spreadsheets = Access::spreadsheets()->where('is_active', true)->get();
                    $spreadsheets->each(fn (Spreadsheet $s) => SyncSpreadsheetJob::start($s, TriggerType::Manual));

                    Notification::make()->title('Sync started for '.$spreadsheets->count().' spreadsheet(s)')->success()->send();
                }),

            Action::make('fetchFromGoogle')
                ->label('Fetch from Google')
                ->visible(fn (): bool => Access::isAdmin())
                ->authorize(fn (): bool => Access::isAdmin())
                ->icon(Heroicon::OutlinedCloudArrowDown)
                ->color('gray')
                // Listing files needs a Google account (OAuth). An API key can only open a sheet it is given.
                ->disabled(fn (): bool => ! self::canListFiles())
                ->tooltip(fn (): ?string => self::canListFiles() ? null : 'Needs a connected Google account (Settings → Google connection). With an API key, use "Add by URL" with a sheet shared as "Anyone with the link".')
                ->modalDescription('Spreadsheets in your connected Google account. Tick the ones to track.')
                ->schema([
                    CheckboxList::make('files')
                        ->label('Spreadsheets')
                        ->options(fn () => $this->driveOptions())
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(2),
                ])
                ->action(fn (array $data) => $this->importMany($data['files'] ?? [])),

            Action::make('addByUrl')
                ->label('Add by URL')
                ->visible(fn (): bool => Access::isAdmin())
                ->authorize(fn (): bool => Access::isAdmin())
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('url')
                        ->label('Google Sheets link or ID')
                        ->placeholder('https://docs.google.com/spreadsheets/d/…/edit')
                        ->required(),
                    TextInput::make('name')->label('Name (optional)')->helperText('Defaults to the spreadsheet title.'),
                ])
                ->action(function (array $data) {
                    $id = A1::spreadsheetIdFromUrl($data['url']);

                    if ($id === null) {
                        throw ValidationException::withMessages(['mountedActions.0.data.url' => 'This doesn\'t look like a Google Sheets link.']);
                    }

                    try {
                        $spreadsheet = app(SpreadsheetImporter::class)->import($id, $data['name'] ?? null);
                    } catch (GoogleAccessException $e) {
                        Notification::make()->title('Could not open the spreadsheet')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title('Added "'.$spreadsheet->name.'"')->body('Now enable the tabs to sync and set up their tables.')->success()->send();
                    $this->redirect(SpreadsheetResource::getUrl('edit', ['record' => $spreadsheet]));
                }),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function driveOptions(): array
    {
        try {
            $files = app(SheetsGateway::class)->listSpreadsheets();
        } catch (GoogleAccessException $e) {
            Notification::make()->title('Could not list your spreadsheets')
                ->body($e->getMessage().' Listing needs a connected Google account (OAuth); with an API key, add sheets by URL.')
                ->danger()->persistent()->send();

            return [];
        }

        $tracked = Spreadsheet::query()->pluck('google_id')->all();

        return collect($files)
            ->reject(fn ($file) => in_array($file->id, $tracked, true))
            ->mapWithKeys(fn ($file) => [$file->id => $file->name])
            ->all();
    }

    /**
     * @param  list<string>  $ids
     */
    private function importMany(array $ids): void
    {
        $added = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                app(SpreadsheetImporter::class)->import($id);
                $added++;
            } catch (GoogleAccessException $e) {
                $failed[] = $e->getMessage();
            }
        }

        Notification::make()
            ->title('Added '.$added.' spreadsheet(s)')
            ->body($failed === [] ? 'Open each one to enable its tabs.' : implode(' ', $failed))
            ->status($failed === [] ? 'success' : 'warning')
            ->send();
    }
}
