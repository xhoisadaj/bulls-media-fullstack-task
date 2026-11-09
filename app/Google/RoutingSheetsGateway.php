<?php

namespace App\Google;

use App\Enums\CredentialType;
use App\Google\Contracts\SheetsGateway;
use App\Google\Data\SpreadsheetMeta;
use Closure;

/**
 * Sends demo spreadsheets (IDs starting with "demo-") to the JSON demo files and every other
 * spreadsheet to Google, so demo data and real files work side by side, without a global switch.
 */
class RoutingSheetsGateway implements SheetsGateway
{
    public const DEMO_PREFIX = 'demo-';

    private ?SheetsGateway $google = null;

    private ?SheetsGateway $last = null;

    /**
     * @param  Closure(): SheetsGateway  $makeGoogle  creates the Google gateway, only when a real spreadsheet is read
     */
    public function __construct(private readonly InMemorySheetsGateway $demo, private readonly Closure $makeGoogle) {}

    public static function isDemo(string $spreadsheetId): bool
    {
        return str_starts_with($spreadsheetId, self::DEMO_PREFIX);
    }

    public function listSpreadsheets(): array
    {
        return $this->use($this->google())->listSpreadsheets();
    }

    public function getSpreadsheet(string $spreadsheetId): SpreadsheetMeta
    {
        return $this->for($spreadsheetId)->getSpreadsheet($spreadsheetId);
    }

    public function readTabs(string $spreadsheetId, array $tabTitles): array
    {
        return $this->for($spreadsheetId)->readTabs($spreadsheetId, $tabTitles);
    }

    public function readFormulas(string $spreadsheetId, string $tabTitle): array
    {
        return $this->for($spreadsheetId)->readFormulas($spreadsheetId, $tabTitle);
    }

    public function lastCredential(): ?CredentialType
    {
        return $this->last?->lastCredential();
    }

    private function for(string $spreadsheetId): SheetsGateway
    {
        return $this->use(self::isDemo($spreadsheetId) ? $this->demo : $this->google());
    }

    private function google(): SheetsGateway
    {
        return $this->google ??= ($this->makeGoogle)();
    }

    private function use(SheetsGateway $gateway): SheetsGateway
    {
        return $this->last = $gateway;
    }
}
