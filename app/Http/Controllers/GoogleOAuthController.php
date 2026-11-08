<?php

namespace App\Http\Controllers;

use App\Enums\ConnectionStatus;
use App\Google\Exceptions\GoogleAccessException;
use App\Google\GoogleCredentialProvider;
use App\Models\CredentialAudit;
use App\Models\GoogleSetting;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * OAuth consent flow: redirect to Google, then exchange the code (server to server)
 * for an access token and a refresh token, stored encrypted.
 */
class GoogleOAuthController extends Controller
{
    public function __construct(private readonly GoogleCredentialProvider $credentials) {}

    public function redirect(Request $request): RedirectResponse
    {
        try {
            $client = $this->credentials->oauthAppClient();
        } catch (GoogleAccessException $e) {
            return $this->back($e->getMessage(), 'danger');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        $client->setScopes(['openid', 'email', GoogleCredentialProvider::SCOPE_DRIVE_LIST, GoogleCredentialProvider::SCOPE_SHEETS_READ]);
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('google_oauth_state');

        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->back('The Google response did not match a login started here, so it was rejected. Try connecting again.', 'danger');
        }

        if ($request->filled('error')) {
            return $this->back('Google did not grant access: '.$request->query('error').'.', 'warning');
        }

        try {
            $client = $this->credentials->oauthAppClient();
        } catch (GoogleAccessException $e) {
            return $this->back($e->getMessage(), 'danger');
        }

        $token = $client->fetchAccessTokenWithAuthCode((string) $request->query('code'));

        if (isset($token['error'])) {
            return $this->back('Google rejected the sign-in: '.($token['error_description'] ?? $token['error']).'. Check the client ID and secret.', 'danger');
        }

        $settings = GoogleSetting::current();
        $refreshToken = $token['refresh_token'] ?? $settings->secret('refresh_token');

        if ($refreshToken === null) {
            return $this->back('Google did not return a refresh token, so the nightly sync could not run. Remove the app at myaccount.google.com/permissions and connect again.', 'danger');
        }

        $settings->forceFill([
            'refresh_token' => $refreshToken,
            'access_token' => $token,
            'access_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'oauth_email' => $this->emailFrom($token['id_token'] ?? null) ?? $settings->oauth_email,
            'scopes' => array_values(array_filter(explode(' ', (string) ($token['scope'] ?? '')))),
            'oauth_status' => ConnectionStatus::Connected,
            'connected_at' => now(),
            'last_error' => null,
            'updated_by' => $request->user()?->id,
        ])->save();

        CredentialAudit::record('oauth_connection', 'connected');

        return $this->back('Google account connected'.($settings->oauth_email ? ' ('.$settings->oauth_email.')' : '').'.', 'success');
    }

    /**
     * Reads the email from the ID token Google returned directly to us over TLS.
     */
    private function emailFrom(?string $idToken): ?string
    {
        if ($idToken === null || substr_count($idToken, '.') !== 2) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/')), true);

        return is_array($payload) && is_string($payload['email'] ?? null) ? $payload['email'] : null;
    }

    private function back(string $message, string $status): RedirectResponse
    {
        Notification::make()->title($message)->status($status)->send();

        return redirect('/admin/google-connection');
    }
}
