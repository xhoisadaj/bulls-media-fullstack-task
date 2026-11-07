<?php

namespace App\Sync;

use App\Enums\ColumnType;
use App\Google\Contracts\SheetsGateway;
use App\Google\Data\TabValues;
use App\Google\Exceptions\GoogleAccessException;
use App\Models\SourceTable;
use App\Models\Tab;

/**
 * Loads the current values of a tab, resolving its title from Google's tab ID first
 * (so renamed tabs keep working).
 */
class TabFetcher
{
    public function __construct(private readonly SheetsGateway $gateway) {}

    /**
     * @throws GoogleAccessException
     */
    public function fetch(Tab $tab, bool $withFormulas = false): TabValues
    {
        $spreadsheet = $tab->spreadsheet;
        $meta = $this->gateway->getSpreadsheet($spreadsheet->google_id);
        $tabMeta = $meta->tabById($tab->google_tab_id);

        if ($tabMeta === null) {
            throw new GoogleAccessException('Tab "'.$tab->name.'" no longer exists in "'.$spreadsheet->name.'".', GoogleAccessException::NOT_ACCESSIBLE);
        }

        if ($tabMeta->title !== $tab->name) {
            $tab->update(['name' => $tabMeta->title]);
        }

        $values = $this->gateway->readTabs($spreadsheet->google_id, [$tabMeta->title])[$tabMeta->title]
            ?? new TabValues([], []);

        if ($withFormulas && $values->formulas === null) {
            $values = $values->withFormulas($this->gateway->readFormulas($spreadsheet->google_id, $tabMeta->title));
        }

        return $values;
    }

    public function needsFormulas(SourceTable $table): bool
    {
        return $table->columns()->where('type', ColumnType::ImageUrl)->exists();
    }
}
