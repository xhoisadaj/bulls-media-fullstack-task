<?php

namespace App\Console\Commands;

use App\Google\GoogleCredentialProvider;
use App\Settings\GoogleSettingsManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('google:configure
    {--client-json= : Path to the OAuth client JSON downloaded from Google Cloud}
    {--skip-oauth : Do not ask for the OAuth client}
    {--skip-api-key : Do not ask for the API key}')]
#[Description('Store the Google OAuth client and API key, encrypted in the database (input is hidden)')]
class GoogleConfigureCommand extends Command
{
    public function handle(GoogleSettingsManager $manager): int
    {
        $this->components->info('Values are stored encrypted in the database (not in .env). Leave a field empty to skip it.');
        $this->components->twoColumnDetail('Redirect URI to register in Google Cloud', GoogleCredentialProvider::redirectUri());

        if (! $this->option('skip-oauth')) {
            $json = null;

            if ($path = $this->option('client-json')) {
                if (! is_readable($path)) {
                    $this->components->error('Cannot read '.$path);

                    return self::FAILURE;
                }

                $json = file_get_contents($path);
            }

            $clientId = $json ? null : text('OAuth client ID', hint: 'Google Cloud → Clients → your Web application client');
            $secret = $json ? null : password('OAuth client secret');

            if ($json || filled($clientId)) {
                try {
                    foreach ($manager->saveOAuthClient($json, $clientId, $secret) as $warning) {
                        $this->components->warn($warning);
                    }

                    $this->components->info('OAuth client saved. Connect your Google account in the admin panel: Settings → Google connection.');
                } catch (InvalidArgumentException $e) {
                    $this->components->error($e->getMessage());

                    return self::FAILURE;
                }
            }
        }

        if (! $this->option('skip-api-key')) {
            $key = password('API key (fallback for public sheets)');

            if (filled($key)) {
                $manager->saveApiKey($key);
                $result = $manager->testApiKey();
                $result === null
                    ? $this->components->info('API key saved and working.')
                    : $this->components->warn('API key saved, but the test failed: '.$result);
            }
        }

        return self::SUCCESS;
    }
}
