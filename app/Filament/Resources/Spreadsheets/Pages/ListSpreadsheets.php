<?php

namespace App\Filament\Resources\Spreadsheets\Pages;

use App\Enums\TriggerType;
use App\Filament\Resources\Spreadsheets\SpreadsheetResource;
use App\Google\A1;
use App\Google\Contracts\SheetsGateway;
use App\Google\Exceptions\GoogleAccessException;
use App\Google\SpreadsheetImporter;
use App\Jobs\SyncSpreadsheetJob;
use App\Models\GoogleSetting;
use App\Models\Spreadsheet;
use Filament\Actions\Action;
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

        return $settings->use_demo_data || $settings->secret('refresh_token') !== null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncAll')
                ->label('Sync all')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function () {
                    $spreadsheets = Spreadsheet::query()->where('is_active', true)->get();
                    $spreadsheets->each(fn (Spreadsheet $s) => SyncSpreadsheetJob::dispatch($s, TriggerType::Manual));

                    Notification::make()->title('Sync started for '.$spreadsheets->count().' spreadsheet(s)')->success()->send();
                }),

            Action::make('fetchFromGoogle')
                ->label('Fetch from Google')
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
