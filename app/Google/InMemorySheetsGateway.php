<?php

namespace App\Google;

use App\Enums\CredentialType;
use App\Google\Contracts\SheetsGateway;
use App\Google\Data\DriveFile;
use App\Google\Data\SpreadsheetMeta;
use App\Google\Data\TabMeta;
use App\Google\Data\TabValues;
use App\Google\Exceptions\GoogleAccessException;
use Illuminate\Support\Facades\File;

/**
 * Spreadsheets held in memory or JSON files. Used by tests and by demo mode, so the app
 * can be explored without any Google account.
 *
 * A cell is either a scalar (same raw and displayed value) or an object
 * {"v": raw, "f": "displayed", "formula": "=..."}.
 */
class InMemorySheetsGateway implements SheetsGateway
{
    /**
     * @var array<string, array{name: string, tabs: list<array{id: int, title: string, rows: list<list<mixed>>}>}>
     */
    private array $spreadsheets = [];

    /** @var array<string, GoogleAccessException> */
    private array $failures = [];

    public function __construct(private readonly ?string $directory = null)
    {
        if ($directory !== null && File::isDirectory($directory)) {
            foreach (File::files($directory) as $file) {
                if ($file->getExtension() === 'json') {
                    $data = json_decode(File::get($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
                    $this->spreadsheets[$data['id']] = ['name' => $data['name'], 'tabs' => $data['tabs']];
                }
            }
        }
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    public function setTab(string $spreadsheetId, string $title, array $rows, int $tabId = 0, ?string $name = null): self
    {
        $this->spreadsheets[$spreadsheetId] ??= ['name' => $name ?? $spreadsheetId, 'tabs' => []];

        foreach ($this->spreadsheets[$spreadsheetId]['tabs'] as $i => $tab) {
            if ($tab['id'] === $tabId) {
                $this->spreadsheets[$spreadsheetId]['tabs'][$i] = ['id' => $tabId, 'title' => $title, 'rows' => $rows];

                return $this;
            }
        }

        $this->spreadsheets[$spreadsheetId]['tabs'][] = ['id' => $tabId, 'title' => $title, 'rows' => $rows];

        return $this;
    }

    public function removeTab(string $spreadsheetId, int $tabId): self
    {
        $this->spreadsheets[$spreadsheetId]['tabs'] = array_values(array_filter(
            $this->spreadsheets[$spreadsheetId]['tabs'] ?? [],
            fn (array $tab) => $tab['id'] !== $tabId,
        ));

        return $this;
    }

    public function failWith(string $spreadsheetId, GoogleAccessException $exception): self
    {
        $this->failures[$spreadsheetId] = $exception;

        return $this;
    }

    public function listSpreadsheets(): array
    {
        return collect($this->spreadsheets)
            ->map(fn (array $s, string $id) => new DriveFile($id, $s['name']))
            ->values()
            ->all();
    }

    public function getSpreadsheet(string $spreadsheetId): SpreadsheetMeta
    {
        $spreadsheet = $this->find($spreadsheetId);

        return new SpreadsheetMeta($spreadsheetId, $spreadsheet['name'], array_map(
            fn (array $tab) => new TabMeta($tab['id'], $tab['title'], count($tab['rows']), max(array_map('count', $tab['rows'] ?: [[]]))),
            $spreadsheet['tabs'],
        ));
    }

    public function readTabs(string $spreadsheetId, array $tabTitles): array
    {
        $spreadsheet = $this->find($spreadsheetId);
        $result = [];

        foreach ($tabTitles as $title) {
            foreach ($spreadsheet['tabs'] as $tab) {
                if ($tab['title'] === $title) {
                    $result[$title] = $this->values($tab['rows']);
                }
            }
        }

        return $result;
    }

    public function readFormulas(string $spreadsheetId, string $tabTitle): array
    {
        $values = $this->readTabs($spreadsheetId, [$tabTitle])[$tabTitle] ?? null;

        return $values?->formulas ?? [];
    }

    public function lastCredential(): ?CredentialType
    {
        return CredentialType::Demo;
    }

    /**
     * @return array{name: string, tabs: list<array{id: int, title: string, rows: list<list<mixed>>}>}
     */
    private function find(string $spreadsheetId): array
    {
        if (isset($this->failures[$spreadsheetId])) {
            throw $this->failures[$spreadsheetId];
        }

        return $this->spreadsheets[$spreadsheetId]
            ?? throw new GoogleAccessException('The file is not accessible ('.$spreadsheetId.' not found).', GoogleAccessException::NOT_ACCESSIBLE);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function values(array $rows): TabValues
    {
        $raw = [];
        $formatted = [];
        $formulas = [];

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $cell) {
                if (is_array($cell)) {
                    $raw[$r][$c] = $cell['v'] ?? ($cell['f'] ?? '');
                    $formatted[$r][$c] = $cell['f'] ?? (is_scalar($cell['v'] ?? null) ? (string) $cell['v'] : '');
                    $formulas[$r][$c] = $cell['formula'] ?? $raw[$r][$c];
                } else {
                    $raw[$r][$c] = $cell;
                    $formatted[$r][$c] = is_bool($cell) ? ($cell ? 'TRUE' : 'FALSE') : (string) ($cell ?? '');
                    $formulas[$r][$c] = $cell;
                }
            }

            $raw[$r] ??= [];
            $formatted[$r] ??= [];
            $formulas[$r] ??= [];
        }

        ksort($raw);
        ksort($formatted);
        ksort($formulas);

        return new TabValues(array_values($raw), array_values($formatted), array_values($formulas));
    }
}
