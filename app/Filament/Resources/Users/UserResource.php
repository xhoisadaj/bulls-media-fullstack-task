<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\SyncSchedules\SyncScheduleResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Settings → Users (admins only): who can sign in, their role, and which spreadsheets they work with.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->rule(Password::min(8))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave empty to keep the current password.' : null),
            Select::make('timezone')
                ->options(SyncScheduleResource::timezones())
                ->searchable()
                ->default(fn () => auth()->user()?->timezone ?? 'UTC')
                ->required(),
            Radio::make('role')
                ->options(UserRole::class)
                ->default(UserRole::Viewer)
                ->required()
                ->live()
                ->disableOptionWhen(fn (string $value, ?User $record): bool => self::wouldRemoveLastAdmin($record, $value))
                ->columnSpanFull(),
            CheckboxList::make('spreadsheets')
                ->label('Spreadsheets this user can access')
                ->relationship('spreadsheets', 'name', fn ($query) => $query->orderBy('name'))
                ->helperText('They see these files and everything that belongs to them: sheet tables, changes, alerts, sync history, and their rows in the database tables.')
                ->bulkToggleable()
                ->columns(3)
                ->visible(fn (Get $get): bool => self::roleValue($get('role')) !== UserRole::Admin->value)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('semibold')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->badge(),
                TextColumn::make('files')
                    ->label('Spreadsheets')
                    ->state(fn (User $record): string => $record->isAdmin()
                        ? 'All'
                        : ($record->spreadsheets->pluck('name')->implode(', ') ?: 'None yet'))
                    ->wrap(),
                TextColumn::make('timezone')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Added')->since(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('spreadsheets'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * An admin can't be turned into a non-admin when they are the last one.
     */
    public static function wouldRemoveLastAdmin(?User $record, string $newRole): bool
    {
        return $record?->isAdmin()
            && $newRole !== UserRole::Admin->value
            && User::query()->where('role', UserRole::Admin)->count() <= 1;
    }

    public static function roleValue(mixed $role): ?string
    {
        return $role instanceof UserRole ? $role->value : (is_string($role) ? $role : null);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
