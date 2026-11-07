<?php

namespace App\Settings;

use App\Enums\ConnectionStatus;
use App\Google\GoogleCredentialProvider;
use App\Models\CredentialAudit;
use App\Models\GoogleSetting;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets;
use InvalidArgumentException;

/**
 * Saves Google credentials (encrypted) and keeps the audit trail. Used by the settings
 * page and by `php artisan google:configure`.
 */
class GoogleSettingsManager
{
    /** A public Google sample sheet, used only to check that an API key works. */
    public const TEST_SPREADSHEET = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';

    /**
     * @return list<string> warnings (e.g. redirect URI not registered in the JSON)
     *
     * @throws InvalidArgumentException
     */
    public function saveOAuthClient(?string $json, ?string $clientId, ?string $clientSecret): array
    {
        $warnings = [];

        if (filled($json)) {
            $decoded = json_decode((string) $json, true);
            $client = is_array($decoded) ? ($decoded['web'] ?? $decoded['installed'] ?? null) : null;

            if (! is_array($client) || empty($client['client_id']) || empty($client['client_secret'])) {
                throw new InvalidArgumentException('This is not an OAuth client JSON from Google Cloud (expected {"web": {"client_id": …, "client_secret": …}}).');
            }

            if (isset($decoded['installed'])) {
                $warnings[] = 'This is a "Desktop app" client. Create a "Web application" client instead, so Google can redirect back to this app.';
            }

            $clientId = $client['client_id'];
            $clientSecret = $client['client_secret'];
            $redirects = $client['redirect_uris'] ?? [];

            if (! in_array(GoogleCredentialProvider::redirectUri(), $redirects, true)) {
                $warnings[] = 'The redirect URI '.GoogleCredentialProvider::redirectUri().' is not registered on this client. Add it in Google Cloud → Clients → Authorized redirect URIs, or the login will fail with redirect_uri_mismatch.';
            }
        }

        if (blank($clientId)) {
            throw new InvalidArgumentException('Enter a client ID or paste the client JSON.');
        }

        $settings = GoogleSetting::current();
        $secretChanged = filled($clientSecret) && $clientSecret !== $settings->secret('oauth_client_secret');
        $clientChanged = $clientId !== $settings->oauth_client_id;

        if (! filled($clientSecret) && $settings->secret('oauth_client_secret') === null) {
            throw new InvalidArgumentException('Enter the client secret.');
        }

        $settings->forceFill([
            'oauth_client_id' => trim((string) $clientId),
            'updated_by' => auth()->id(),
        ]);

        if ($secretChanged) {
            $settings->oauth_client_secret = trim((string) $clientSecret);
        }

        if (($secretChanged || $clientChanged) && $settings->secret('refresh_token') !== null) {
            // The refresh token belongs to the old client, so it can't be used any more.
            $settings->forceFill(['refresh_token' => null, 'access_token' => null, 'oauth_status' => ConnectionStatus::NotSet, 'scopes' => null]);
            $warnings[] = 'The OAuth client changed, so the Google account was disconnected. Connect it again.';
        }

        $settings->save();
        CredentialAudit::record('oauth_client', $secretChanged || $clientChanged ? 'replaced' : 'set');

        return $warnings;
    }

    public function disconnect(): void
    {
        GoogleSetting::current()->forceFill([
            'refresh_token' => null,
            'access_token' => null,
            'oauth_status' => ConnectionStatus::NotSet,
            'oauth_email' => null,
            'scopes' => null,
            'updated_by' => auth()->id(),
        ])->save();

        CredentialAudit::record('oauth_connection', 'disconnected');
    }

    public function saveApiKey(string $key): void
    {
        $settings = GoogleSetting::current();
        $action = $settings->secret('api_key') === null ? 'set' : 'replaced';

        $settings->forceFill([
            'api_key' => trim($key),
            'api_key_status' => ConnectionStatus::NotSet,
            'updated_by' => auth()->id(),
        ])->save();

        CredentialAudit::record('api_key', $action);
    }

    public function removeApiKey(): void
    {
        GoogleSetting::current()->forceFill(['api_key' => null, 'api_key_status' => ConnectionStatus::NotSet, 'updated_by' => auth()->id()])->save();
        CredentialAudit::record('api_key', 'removed');
    }

    /**
     * Makes one small request with the API key. Returns null when it works, otherwise the reason.
     */
    public function testApiKey(): ?string
    {
        $settings = GoogleSetting::current();
        $key = $settings->secret('api_key');

        if ($key === null) {
            return 'No API key is set.';
        }

        $client = new Client;
        $client->setDeveloperKey($key);

        try {
            (new Sheets($client))->spreadsheets->get(self::TEST_SPREADSHEET, ['fields' => 'properties(title)']);
        } catch (GoogleServiceException $e) {
            $message = $e->getErrors()[0]['message'] ?? $e->getMessage();
            $settings->forceFill(['api_key_status' => ConnectionStatus::Invalid, 'last_error' => $message, 'last_checked_at' => now()])->save();

            return (string) $message;
        } catch (\Throwable $e) {
            return 'Could not reach Google: '.$e->getMessage();
        }

        $settings->forceFill(['api_key_status' => ConnectionStatus::Valid, 'last_checked_at' => now()])->save();

        return null;
    }
}
