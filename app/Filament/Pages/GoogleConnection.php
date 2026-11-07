<?php

namespace App\Filament\Pages;

use App\Enums\ConnectionStatus;
use App\Google\GoogleCredentialProvider;
use App\Models\CredentialAudit;
use App\Models\GoogleSetting;
use App\Settings\GoogleSettingsManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Settings → Google connection. OAuth is the default; the API key is the fallback for public
 * sheets. Secrets are write-only: after saving only a masked hint is shown, and changing one
 * asks for the admin's password again.
 */
class GoogleConnection extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Google connection';

    protected static ?string $slug = 'google-connection';

    public function content(Schema $schema): Schema
    {
        $settings = GoogleSetting::current();
        $this->flagUnreadableSecrets($settings);

        return $schema->components([
            Section::make('Status')
                ->columns(4)
                ->schema([
                    TextEntry::make('oauth_status')->label('OAuth')->badge()
                        ->state($settings->oauth_status)
                        ->color($settings->oauth_status->getColor()),
                    TextEntry::make('oauth_email')->label('Connected account')->state($settings->oauth_email ?? '—'),
                    TextEntry::make('api_key_status')->label('API key')->badge()
                        ->state($settings->api_key_status)
                        ->color($settings->api_key_status->getColor()),
                    TextEntry::make('in_use')->label('Currently using')->state($this->currentlyUsing($settings)),
                ]),

            Section::make('OAuth (default)')
                ->description('Reads the private sheets your Google account can open. Nothing has to be shared with an email.')
                ->columns(2)
                ->schema([
                    TextEntry::make('client_id')->label('Client ID')->state($settings->oauth_client_id ?? 'Not set')->copyable(filled($settings->oauth_client_id)),
                    TextEntry::make('client_secret')->label('Client secret')->state($settings->maskedHint('oauth_client_secret') ?? 'Not set'),
                    TextEntry::make('redirect_uri')->label('Redirect URI (register this in Google Cloud → Clients)')
                        ->state(GoogleCredentialProvider::redirectUri())->copyable()->columnSpanFull(),
                    TextEntry::make('scopes')->label('Granted access')->columnSpanFull()
                        ->state($this->describeScopes($settings)),
                    Actions::make([
                        $this->editClientAction(),
                        Action::make('connect')
                            ->label($settings->secret('refresh_token') ? 'Reconnect Google account' : 'Connect Google account')
                            ->icon(Heroicon::OutlinedLink)
                            ->url(route('google.connect'))
                            ->disabled(! $settings->hasOAuthClient())
                            ->tooltip($settings->hasOAuthClient() ? null : 'Save the client ID and secret first'),
                        Action::make('connectWrite')
                            ->label('Connect with write access')
                            ->color('gray')
                            ->url(route('google.connect', ['write' => 1]))
                            ->disabled(! $settings->hasOAuthClient())
                            ->tooltip('Only needed for system ID mode, which writes a _sync_id column into the sheet'),
                        $this->disconnectAction(),
                    ])->columnSpanFull(),
                ]),

            Section::make('API key (fallback)')
                ->description('Used when OAuth is not connected or can\'t open a file. It can only read sheets shared as "Anyone with the link can view".')
                ->schema([
                    TextEntry::make('api_key')->label('Key')->state($settings->maskedHint('api_key') ?? 'Not set'),
                    Actions::make([
                        $this->setApiKeyAction(),
                        Action::make('testKey')
                            ->label('Test key')
                            ->color('gray')
                            ->disabled($settings->secret('api_key') === null)
                            ->action(fn () => $this->testApiKey()),
                        $this->removeApiKeyAction(),
                    ]),
                ]),

            Section::make('Demo data')
                ->description('Explore the app without any Google account: spreadsheets are read from JSON files in storage/app/demo. Run "php artisan sheets:demo" to load the demo setup.')
                ->schema([
                    TextEntry::make('demo')->label('Demo mode')->badge()
                        ->state($settings->use_demo_data ? 'On' : 'Off')
                        ->color($settings->use_demo_data ? 'warning' : 'gray'),
                    Actions::make([
                        Action::make('toggleDemo')
                            ->label($settings->use_demo_data ? 'Turn demo mode off (use Google)' : 'Turn demo mode on')
                            ->color('gray')
                            ->requiresConfirmation()
                            ->action(function () use ($settings) {
                                $settings->update(['use_demo_data' => ! $settings->use_demo_data]);
                                Notification::make()->title('Demo mode '.($settings->use_demo_data ? 'on' : 'off'))->success()->send();
                            }),
                    ]),
                ]),

            Section::make('Recent credential changes')
                ->description('Who changed which credential and when. Values are never stored here.')
                ->collapsible()
                ->schema([
                    Text::make($this->auditSummary()),
                ]),
        ]);
    }

    public function editClientAction(): Action
    {
        return Action::make('editClient')
            ->label('Set OAuth client')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalDescription('Paste the client JSON downloaded from Google Cloud, or fill in the client ID and secret. Leave the secret empty to keep the saved one.')
            ->schema([
                Textarea::make('client_json')
                    ->label('Client JSON (optional)')
                    ->rows(4)
                    ->placeholder('{"web":{"client_id":"…","client_secret":"…"}}'),
                TextInput::make('client_id')->label('Client ID')->default(GoogleSetting::current()->oauth_client_id),
                TextInput::make('client_secret')->label('Client secret')->password()->revealable()
                    ->placeholder(GoogleSetting::current()->maskedHint('oauth_client_secret') ?? ''),
                TextInput::make('current_password')->label('Your password')->password()->required()->currentPassword(),
            ])
            ->action(function (array $data) {
                try {
                    $warnings = app(GoogleSettingsManager::class)->saveOAuthClient($data['client_json'] ?? null, $data['client_id'] ?? null, $data['client_secret'] ?? null);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['mountedActions.0.data.client_json' => $e->getMessage()]);
                }

                Notification::make()->title('OAuth client saved')->body(implode(' ', $warnings) ?: null)
                    ->status($warnings === [] ? 'success' : 'warning')->persistent($warnings !== [])->send();
            });
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->color('danger')
            ->visible(GoogleSetting::current()->secret('refresh_token') !== null)
            ->requiresConfirmation()
            ->modalDescription('The nightly sync will use the API key fallback (public sheets only) until you connect again.')
            ->schema([TextInput::make('current_password')->label('Your password')->password()->required()->currentPassword()])
            ->action(function () {
                app(GoogleSettingsManager::class)->disconnect();
                Notification::make()->title('Google account disconnected')->success()->send();
            });
    }

    public function setApiKeyAction(): Action
    {
        return Action::make('setApiKey')
            ->label(GoogleSetting::current()->secret('api_key') ? 'Replace API key' : 'Set API key')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->schema([
                TextInput::make('api_key')->label('API key')->password()->revealable()->required()->minLength(20),
                TextInput::make('current_password')->label('Your password')->password()->required()->currentPassword(),
            ])
            ->action(function (array $data) {
                app(GoogleSettingsManager::class)->saveApiKey($data['api_key']);
                Notification::make()->title('API key saved')->body('Use "Test key" to check it works.')->success()->send();
            });
    }

    public function removeApiKeyAction(): Action
    {
        return Action::make('removeApiKey')
            ->label('Remove')
            ->color('danger')
            ->visible(GoogleSetting::current()->secret('api_key') !== null)
            ->requiresConfirmation()
            ->schema([TextInput::make('current_password')->label('Your password')->password()->required()->currentPassword()])
            ->action(function () {
                app(GoogleSettingsManager::class)->removeApiKey();
                Notification::make()->title('API key removed')->success()->send();
            });
    }

    private function testApiKey(): void
    {
        $result = app(GoogleSettingsManager::class)->testApiKey();

        Notification::make()
            ->title($result === null ? 'The API key works with the Google Sheets API' : 'The API key did not work')
            ->body($result)
            ->status($result === null ? 'success' : 'danger')
            ->send();
    }

    private function currentlyUsing(GoogleSetting $settings): string
    {
        return match (true) {
            $settings->use_demo_data => 'Demo data',
            $settings->oauth_status === ConnectionStatus::Connected => 'OAuth',
            $settings->secret('api_key') !== null => 'API key (fallback)',
            default => 'Nothing yet',
        };
    }

    private function describeScopes(GoogleSetting $settings): string
    {
        $scopes = $settings->scopes ?? [];

        if ($scopes === []) {
            return 'Not connected';
        }

        return collect([
            GoogleCredentialProvider::SCOPE_SHEETS_WRITE => 'Read and write sheets',
            GoogleCredentialProvider::SCOPE_SHEETS_READ => 'Read sheets',
            GoogleCredentialProvider::SCOPE_DRIVE_LIST => 'List your spreadsheets',
        ])->filter(fn ($label, $scope) => in_array($scope, $scopes, true))->implode(' · ') ?: implode(', ', $scopes);
    }

    private function auditSummary(): string
    {
        $audits = CredentialAudit::query()->with('user')->latest('id')->limit(10)->get();

        if ($audits->isEmpty()) {
            return 'No changes yet.';
        }

        return $audits->map(fn (CredentialAudit $a) => $a->created_at?->format('Y-m-d H:i').' · '.($a->user?->name ?? 'system').' '.$a->action.' '.str_replace('_', ' ', $a->credential))->implode("\n");
    }

    private function flagUnreadableSecrets(GoogleSetting $settings): void
    {
        $changes = [];

        if ($settings->secretIsUnreadable('refresh_token') || $settings->secretIsUnreadable('oauth_client_secret')) {
            $changes['oauth_status'] = ConnectionStatus::Unreadable;
        }

        if ($settings->secretIsUnreadable('api_key')) {
            $changes['api_key_status'] = ConnectionStatus::Unreadable;
        }

        if ($changes !== []) {
            $settings->forceFill($changes)->save();
        }
    }
}
