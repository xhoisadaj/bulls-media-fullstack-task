<?php

namespace App\Google;

use App\Google\Contracts\SheetsGateway;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\Spreadsheet;
use App\Models\Tab;

/**
 * Adds spreadsheets to the app and keeps their list of tabs in step with Google.
 */
class SpreadsheetImporter
{
    public function __construct(private readonly SheetsGateway $gateway) {}

    /**
     * @throws GoogleAccessException
     */
    public function import(string $googleId, ?string $name = null): Spreadsheet
    {
        $meta = $this->gateway->getSpreadsheet($googleId);

        $spreadsheet = Spreadsheet::query()->firstOrCreate(
            ['google_id' => $googleId],
            ['name' => $name ?: $meta->title, 'is_active' => true],
        );

        $this->refreshTabs($spreadsheet, $meta);

        return $spreadsheet;
    }

    /**
     * @throws GoogleAccessException
     */
    public function refreshTabs(Spreadsheet $spreadsheet, ?Data\SpreadsheetMeta $meta = null): void
    {
        $meta ??= $this->gateway->getSpreadsheet($spreadsheet->google_id);
        $seen = [];

        foreach ($meta->tabs as $tabMeta) {
            $seen[] = $tabMeta->id;

            Tab::query()->updateOrCreate(
                ['spreadsheet_id' => $spreadsheet->id, 'google_tab_id' => $tabMeta->id],
                ['name' => $tabMeta->title, 'is_present' => true],
            );
        }

        $spreadsheet->tabs()->whereNotIn('google_tab_id', $seen)->update(['is_present' => false]);
    }
}
