<?php

namespace App\Google;

use App\Enums\ConnectionStatus;
use App\Enums\CredentialType;
use App\Google\Contracts\SheetsGateway;
use App\Google\Data\DriveFile;
use App\Google\Data\SpreadsheetMeta;
use App\Google\Data\TabMeta;
use App\Google\Data\TabValues;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\GoogleSetting;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ValueRange;
use Throwable;

/**
 * Real Google Sheets access. Every read tries OAuth first and falls back to the API key
 * only for authentication or access errors, never for rate limits or outages.
 */
class GoogleSheetsGateway implements SheetsGateway
{
    private ?CredentialType $lastCredential = null;

    public function __construct(private readonly GoogleCredentialProvider $credentials) {}

    public function listSpreadsheets(): array
    {
        return $this->withCredential(CredentialType::OAuth, function (Client $client): array {
            $drive = new Drive($client);
            $files = [];
            $pageToken = null;

            do {
                $response = $drive->files->listFiles([
                    'q' => "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false",
                    'fields' => 'nextPageToken, files(id, name, modifiedTime)',
                    'orderBy' => 'modifiedTime desc',
                    'pageSize' => 100,
                    'pageToken' => $pageToken,
                ]);

                foreach ($response->getFiles() as $file) {
                    $files[] = new DriveFile($file->getId(), $file->getName(), $file->getModifiedTime());
                }

                $pageToken = $response->getNextPageToken();
            } while ($pageToken !== null && count($files) < 500);

            return $files;
        });
    }

    public function getSpreadsheet(string $spreadsheetId): SpreadsheetMeta
    {
        return $this->withFallback(function (Client $client) use ($spreadsheetId): SpreadsheetMeta {
            $spreadsheet = (new Sheets($client))->spreadsheets->get($spreadsheetId, [
                'fields' => 'properties(title),sheets(properties(sheetId,title,gridProperties(rowCount,columnCount)))',
            ]);

            $tabs = [];

            foreach ($spreadsheet->getSheets() as $sheet) {
                $properties = $sheet->getProperties();
                $grid = $properties->getGridProperties();
                $tabs[] = new TabMeta(
                    (int) $properties->getSheetId(),
                    (string) $properties->getTitle(),
                    (int) ($grid?->getRowCount() ?? 0),
                    (int) ($grid?->getColumnCount() ?? 0),
                );
            }

            return new SpreadsheetMeta($spreadsheetId, (string) $spreadsheet->getProperties()->getTitle(), $tabs);
        });
    }

    public function readTabs(string $spreadsheetId, array $tabTitles): array
    {
        if ($tabTitles === []) {
            return [];
        }

        return $this->withFallback(function (Client $client) use ($spreadsheetId, $tabTitles): array {
            $sheets = new Sheets($client);
            $ranges = array_map(A1::tab(...), $tabTitles);

            $raw = $sheets->spreadsheets_values->batchGet($spreadsheetId, [
                'ranges' => $ranges,
                'valueRenderOption' => 'UNFORMATTED_VALUE',
                'dateTimeRenderOption' => 'SERIAL_NUMBER',
                'majorDimension' => 'ROWS',
            ])->getValueRanges();

            $formatted = $sheets->spreadsheets_values->batchGet($spreadsheetId, [
                'ranges' => $ranges,
                'valueRenderOption' => 'FORMATTED_VALUE',
                'majorDimension' => 'ROWS',
            ])->getValueRanges();

            $result = [];

            foreach (array_values($tabTitles) as $index => $title) {
                $result[$title] = new TabValues(
                    $raw[$index]?->getValues() ?? [],
                    $formatted[$index]?->getValues() ?? [],
                );
            }

            return $result;
        });
    }

    public function readFormulas(string $spreadsheetId, string $tabTitle): array
    {
        return $this->withFallback(function (Client $client) use ($spreadsheetId, $tabTitle): array {
            return (new Sheets($client))->spreadsheets_values->get($spreadsheetId, A1::tab($tabTitle), [
                'valueRenderOption' => 'FORMULA',
            ])->getValues() ?? [];
        });
    }

    public function writeCells(string $spreadsheetId, string $tabTitle, array $cells): void
    {
        if ($cells === []) {
            return;
        }

        if (! $this->canWrite()) {
            throw new GoogleAccessException('Writing to the sheet needs OAuth with write access.', GoogleAccessException::WRITE_NOT_ALLOWED);
        }

        $this->withCredential(CredentialType::OAuth, function (Client $client) use ($spreadsheetId, $tabTitle, $cells): void {
            $data = [];

            foreach ($cells as $cell => $value) {
                $data[] = new ValueRange(['range' => A1::tab($tabTitle).'!'.$cell, 'values' => [[$value]]]);
            }

            (new Sheets($client))->spreadsheets_values->batchUpdate($spreadsheetId, new BatchUpdateValuesRequest([
                'valueInputOption' => 'RAW',
                'data' => $data,
            ]));
        });
    }

    public function canWrite(): bool
    {
        return $this->credentials->hasWriteScope();
    }

    public function lastCredential(): ?CredentialType
    {
        return $this->lastCredential;
    }

    /**
     * Runs the call with OAuth, then with the API key when OAuth fails for an auth/access reason.
     *
     * @template T
     *
     * @param  callable(Client): T  $call
     * @return T
     *
     * @throws GoogleAccessException
     */
    private function withFallback(callable $call): mixed
    {
        $order = $this->credentials->order();

        if ($order === []) {
            throw new GoogleAccessException(
                'No Google credentials are set. Connect a Google account or add an API key in Settings → Google connection.',
                GoogleAccessException::NOT_CONFIGURED,
            );
        }

        $attempts = [];
        $last = null;

        foreach ($order as $type) {
            try {
                return $this->withCredential($type, $call);
            } catch (GoogleAccessException $e) {
                $attempts[$type->value] = $e->getMessage();
                $last = $e;

                if (! $e->allowsFallback()) {
                    throw $e;
                }
            }
        }

        $message = collect($attempts)
            ->map(fn (string $reason, string $type) => (CredentialType::from($type)->getLabel()).': '.$reason)
            ->implode(' · ');

        throw new GoogleAccessException($message, $last?->reason ?? GoogleAccessException::NOT_ACCESSIBLE, $attempts, $last);
    }

    /**
     * @template T
     *
     * @param  callable(Client): T  $call
     * @return T
     *
     * @throws GoogleAccessException
     */
    private function withCredential(CredentialType $type, callable $call): mixed
    {
        $client = $this->credentials->client($type);

        try {
            $result = $call($client);
        } catch (GoogleServiceException $e) {
            throw $this->translate($e, $type);
        } catch (GoogleAccessException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GoogleAccessException('Google request failed: '.$e->getMessage(), GoogleAccessException::UNAVAILABLE, [], $e);
        }

        $this->lastCredential = $type;

        if ($type === CredentialType::ApiKey) {
            $settings = GoogleSetting::current();

            if ($settings->api_key_status !== ConnectionStatus::Valid) {
                $settings->forceFill(['api_key_status' => ConnectionStatus::Valid])->save();
            }
        }

        return $result;
    }

    private function translate(GoogleServiceException $e, CredentialType $type): GoogleAccessException
    {
        $status = (int) $e->getCode();
        $message = $this->errorMessage($e);
        $lower = strtolower($message);

        if ($type === CredentialType::ApiKey && ($status === 400 && str_contains($lower, 'api key') || str_contains($lower, 'api_key') || str_contains($lower, 'blocked'))) {
            GoogleSetting::current()->forceFill(['api_key_status' => ConnectionStatus::Invalid, 'last_error' => $message])->save();

            return new GoogleAccessException('The API key was rejected: '.$message, GoogleAccessException::INVALID_KEY, [], $e);
        }

        return match (true) {
            $status === 401 => new GoogleAccessException('Google rejected the credentials: '.$message, GoogleAccessException::AUTH_FAILED, [], $e),
            in_array($status, [403, 404], true) && $type === CredentialType::ApiKey => new GoogleAccessException(
                'The sheet is not public. Set sharing to "Anyone with the link can view", or connect a Google account.',
                GoogleAccessException::NOT_PUBLIC, [], $e,
            ),
            in_array($status, [403, 404], true) => new GoogleAccessException('The file is not accessible with this Google account ('.$message.').', GoogleAccessException::NOT_ACCESSIBLE, [], $e),
            $status === 429 => new GoogleAccessException('Google rate limit reached. The next sync will try again.', GoogleAccessException::RATE_LIMITED, [], $e),
            default => new GoogleAccessException('Google error '.$status.': '.$message, GoogleAccessException::UNAVAILABLE, [], $e),
        };
    }

    private function errorMessage(GoogleServiceException $e): string
    {
        $errors = $e->getErrors();

        if (is_array($errors) && isset($errors[0]['message'])) {
            return (string) $errors[0]['message'];
        }

        $decoded = json_decode($e->getMessage(), true);

        return (string) ($decoded['error']['message'] ?? $e->getMessage());
    }
}
