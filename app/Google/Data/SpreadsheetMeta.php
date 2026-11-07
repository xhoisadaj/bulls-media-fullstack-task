<?php

namespace App\Google\Data;

final readonly class SpreadsheetMeta
{
    /**
     * @param  list<TabMeta>  $tabs
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $tabs,
    ) {}

    public function tabById(int $tabId): ?TabMeta
    {
        foreach ($this->tabs as $tab) {
            if ($tab->id === $tabId) {
                return $tab;
            }
        }

        return null;
    }
}
