<?php

namespace App\Filament\Resources\SyncSchedules;

use App\Filament\Resources\SyncSchedules\Pages\ManageSyncSchedules;
use App\Models\Spreadsheet;
use App\Models\SyncSchedule;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * When the files are synced: any number of times a day, on chosen weekdays, in a chosen timezone.
 */
class SyncScheduleResource extends Resource
{
    protected static ?string $model = SyncSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Activity';

    protected static ?string $navigationLabel = 'Sync schedules';

    protected static ?string $modelLabel = 'sync schedule';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::formComponents())->columns(1);
    }

    /**
     * @return list<Component|Field>
     */
    public static function formComponents(): array
    {
        return [
            Select::make('spreadsheet_id')
                ->label('Files')
                ->placeholder('All files')
                ->options(fn () => Spreadsheet::query()->orderBy('name')->pluck('name', 'id'))
                ->helperText('Leave empty to sync every active file.'),
            Repeater::make('times')
                ->label('Times of day')
                ->simple(TimePicker::make('time')->seconds(false)->required())
                ->defaultItems(1)
                ->minItems(1)
                ->addActionLabel('Add a time')
                ->helperText('Add several times to sync more than once a day.'),
            CheckboxList::make('days')
                ->label('Days')
                ->options(SyncSchedule::DAYS)
                ->default(array_keys(SyncSchedule::DAYS))
                ->columns(7)
                ->bulkToggleable()
                ->required(),
            Select::make('timezone')
                ->label('Timezone')
                ->options(self::timezones())
                ->searchable()
                ->default(fn () => auth()->user()?->timezone ?? config('app.timezone'))
                ->required(),
            Toggle::make('is_active')->label('Active')->default(true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function timezones(): array
    {
        $zones = DateTimeZone::listIdentifiers();

        return array_combine($zones, $zones);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('spreadsheet.name')->label('Files')->placeholder('All files')->weight('semibold'),
                TextColumn::make('times')->label('Times')
                    ->state(fn (SyncSchedule $record): string => implode(', ', collect($record->normalizedTimes())->sort()->all())),
                TextColumn::make('days')->label('Days')->state(fn (SyncSchedule $record): string => $record->daysLabel()),
                TextColumn::make('timezone'),
                TextColumn::make('next_run')->label('Next run')
                    ->state(fn (SyncSchedule $record): string => $record->is_active
                        ? ($record->nextRunAt(CarbonImmutable::now())?->format('D Y-m-d H:i T') ?? '—')
                        : 'paused'),
                TextColumn::make('last_triggered_at')->label('Last run')->since()->placeholder('never'),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->recordActions([
                Action::make('runNow')
                    ->label('Run now')
                    ->icon(Heroicon::OutlinedPlay)
                    ->action(fn (SyncSchedule $record) => ManageSyncSchedules::runNow($record)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No schedules')
            ->emptyStateDescription('Files are only synced when you click "Sync now". Add a schedule to sync them automatically.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSyncSchedules::route('/'),
        ];
    }
}
