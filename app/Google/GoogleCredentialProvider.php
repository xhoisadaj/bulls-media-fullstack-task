<?php

namespace App\Google;

use App\Enums\ConnectionStatus;
use App\Enums\CredentialType;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\GoogleSetting;
use Google\Client;

/**
 * Builds authenticated Google clients from the encrypted settings: OAuth first (default),
 * then the API key as fallback for public sheets.
 */
class GoogleCredentialProvider
{
    public const SCOPE_SHEETS_READ = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    public const SCOPE_DRIVE_LIST = 'https://www.googleapis.com/auth/drive.metadata.readonly';

    /**
     * Credentials to try, in order. OAuth is listed even when expired so the caller
     * gets a clear reason; the API key only when one is stored.
     *
     * @return list<CredentialType>
     */
    public function order(): array
    {
        $settings = GoogleSetting::current();
        $order = [];

        if ($settings->secret('refresh_token') !== null) {
            $order[] = CredentialType::OAuth;
        }

        if ($settings->secret('api_key') !== null) {
            $order[] = CredentialType::ApiKey;
        }

        return $order;
    }

    /**
     * @throws GoogleAccessException
     */
    public function client(CredentialType $type): Client
    {
        return match ($type) {
            CredentialType::OAuth => $this->oauthClient(),
            CredentialType::ApiKey => $this->apiKeyClient(),
            CredentialType::Demo => throw new GoogleAccessException('Demo data does not use Google.', GoogleAccessException::NOT_CONFIGURED),
        };
    }

    /**
     * A bare client configured with the OAuth app (client ID, secret, redirect URI), used for the consent flow.
     *
     * @throws GoogleAccessException
     */
    public function oauthAppClient(): Client
    {
        $settings = GoogleSetting::current();

        if (! $settings->hasOAuthClient()) {
            throw new GoogleAccessException('Save the OAuth client ID and secret first.', GoogleAccessException::NOT_CONFIGURED);
        }

        $client = $this->baseClient();
        $client->setClientId($settings->oauth_client_id);
        $client->setClientSecret($settings->secret('oauth_client_secret'));
        $client->setRedirectUri(self::redirectUri());
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/google/callback';
    }

    /**
     * @throws GoogleAccessException
     */
    private function oauthClient(): Client
    {
        $settings = GoogleSetting::current();
        $refreshToken = $settings->secret('refresh_token');

        if ($refreshToken === null) {
            throw new GoogleAccessException('Google account is not connected.', GoogleAccessException::NOT_CONFIGURED);
        }

        if (in_array($settings->oauth_status, [ConnectionStatus::Expired, ConnectionStatus::Revoked], true)) {
            throw new GoogleAccessException('Google account connection '.$settings->oauth_status->value.'. Reconnect it in Settings.', GoogleAccessException::AUTH_FAILED);
        }

        $client = $this->oauthAppClient();
        $token = $settings->access_token;

        if (is_array($token) && isset($token['access_token'])) {
            $client->setAccessToken($token);
        }

        if (! is_array($token) || $client->isAccessTokenExpired()) {
            $fresh = $client->fetchAccessTokenWithRefreshToken($refreshToken);

            if (isset($fresh['error'])) {
                $description = (string) ($fresh['error_description'] ?? $fresh['error']);
                $status = str_contains(strtolower($description), 'revoked') && ! str_contains(strtolower($description), 'expired')
                    ? ConnectionStatus::Revoked
                    : ConnectionStatus::Expired;

                $settings->forceFill([
                    'oauth_status' => $status,
                    'last_error' => $description,
                    'access_token' => null,
                ])->save();

                throw new GoogleAccessException('Google account connection '.$status->value.': '.$description, GoogleAccessException::AUTH_FAILED);
            }

            $settings->forceFill([
                'access_token' => $fresh,
                'access_token_expires_at' => now()->addSeconds((int) ($fresh['expires_in'] ?? 3600)),
                'last_checked_at' => now(),
            ])->save();
        }

        return $client;
    }

    /**
     * @throws GoogleAccessException
     */
    private function apiKeyClient(): Client
    {
        $key = GoogleSetting::current()->secret('api_key');

        if ($key === null) {
            throw new GoogleAccessException('No API key is set.', GoogleAccessException::NOT_CONFIGURED);
        }

        $client = $this->baseClient();
        $client->setDeveloperKey($key);

        return $client;
    }

    private function baseClient(): Client
    {
        $client = new Client([
            'retry' => ['retries' => 3, 'initial_delay' => 1, 'delay_factor' => 2],
        ]);
        $client->setApplicationName((string) config('app.name'));

        return $client;
    }
}
