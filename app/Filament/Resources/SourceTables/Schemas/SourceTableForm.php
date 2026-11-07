<?php

namespace App\Filament\Resources\SourceTables\Schemas;

use App\Enums\ApplyMode;
use App\Enums\EndRule;
use App\Enums\IdentityMode;
use App\Models\SourceTable;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Edit form for an existing source table (the create flow is a wizard).
 */
class SourceTableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Table')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Toggle::make('is_active')->label('Include in sync')->inline(false),
                    TextInput::make('header_row')->label('Header row (hint)')->numeric()->minValue(1)->required()
                        ->helperText('Where the headers were last found. If they move, the table is found again by header names.'),
                    TextInput::make('start_column')->label('Start column number (hint)')->numeric()->minValue(1)->required()
                        ->helperText('A = 1, B = 2, … H = 8.'),
                    Select::make('end_rule')->options(EndRule::class)->required()->live(),
                    TextInput::make('end_row')->label('Last row')->numeric()->minValue(1)
                        ->visible(fn (Get $get) => self::enumValue($get('end_rule')) === EndRule::FixedRow->value)
                        ->required(fn (Get $get) => self::enumValue($get('end_rule')) === EndRule::FixedRow->value),
                ]),
            Section::make('Row identity and review')
                ->schema(self::identityFields(fn (?SourceTable $record) => $record?->columns()->pluck('header', 'id')->all() ?? [], 'key_column_ids')),
        ]);
    }

    /**
     * Identity mode, key columns and apply mode, shared with the create wizard.
     *
     * @param  callable  $keyOptions  returns [value => label] for the key column select
     * @return list<Component>
     */
    public static function identityFields(callable $keyOptions, string $keyField): array
    {
        return [
            Radio::make('identity_mode')
                ->label('How are rows recognised between syncs?')
                ->options(IdentityMode::class)
                ->default(IdentityMode::Content)
                ->required()
                ->live(),
            Select::make($keyField)
                ->label('Key column(s)')
                ->helperText('Together they must be unique per row and never empty. Checked against the current data.')
                ->multiple()
                ->options($keyOptions)
                ->visible(fn (Get $get) => self::enumValue($get('identity_mode')) === IdentityMode::Key->value)
                ->required(fn (Get $get) => self::enumValue($get('identity_mode')) === IdentityMode::Key->value),
            Radio::make('apply_mode')
                ->label('When changes are found')
                ->options(ApplyMode::class)
                ->default(ApplyMode::Review)
                ->required(),
        ];
    }

    public static function enumValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (is_string($value) ? $value : null);
    }
}
