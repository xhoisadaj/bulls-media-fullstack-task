<?php

namespace App\Google\Contracts;

use App\Enums\CredentialType;
use App\Google\Data\DriveFile;
use App\Google\Data\SpreadsheetMeta;
use App\Google\Data\TabValues;
use App\Google\Exceptions\GoogleAccessException;

/**
 * The only way the app talks to Google Sheets. Everything else receives plain arrays.
 */
interface SheetsGateway
{
    /**
     * Spreadsheets visible to the connected Google account (OAuth only).
     *
     * @return list<DriveFile>
     *
     * @throws GoogleAccessException
     */
    public function listSpreadsheets(): array;

    /**
     * Title and tabs (with Google's tab IDs) of a spreadsheet.
     *
     * @throws GoogleAccessException
     */
    public function getSpreadsheet(string $spreadsheetId): SpreadsheetMeta;

    /**
     * Reads whole tabs in one batch: raw values and displayed text.
     *
     * @param  list<string>  $tabTitles
     * @return array<string, TabValues> keyed by tab title
     *
     * @throws GoogleAccessException
     */
    public function readTabs(string $spreadsheetId, array $tabTitles): array;

    /**
     * Reads the formulas of a tab (for =IMAGE() URLs and volatile-formula warnings).
     *
     * @return list<list<mixed>>
     *
     * @throws GoogleAccessException
     */
    public function readFormulas(string $spreadsheetId, string $tabTitle): array;

    /**
     * Writes single cells (A1 notation inside the tab => value). Needs OAuth with write access.
     *
     * @param  array<string, string>  $cells
     *
     * @throws GoogleAccessException
     */
    public function writeCells(string $spreadsheetId, string $tabTitle, array $cells): void;

    /**
     * Whether writing to sheets is possible right now (OAuth connected with the write scope).
     */
    public function canWrite(): bool;

    /**
     * Which credential served the last successful call.
     */
    public function lastCredential(): ?CredentialType;
}
