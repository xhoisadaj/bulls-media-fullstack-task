<?php

namespace App\Filament\Resources\SourceTables\Pages;

use App\Enums\IdentityMode;
use App\Filament\Resources\SourceTables\SourceTableResource;
use App\Sync\Backfiller;
use App\Sync\Fingerprinter;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditSourceTable extends EditRecord
{
    protected static string $resource = SourceTableResource::class;

    /** @var list<int> */
    private array $trackedBefore = [];

    private bool $identityChanged = false;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function beforeSave(): void
    {
        $this->trackedBefore = $this->record->trackedColumns()->pluck('id')->all();
        $data = $this->form->getState();
        $mode = IdentityMode::from($data['identity_mode'] instanceof IdentityMode ? $data['identity_mode']->value : $data['identity_mode']);
        $keys = array_map('intval', $data['key_column_ids'] ?? []);

        $this->identityChanged = $mode !== $this->record->identity_mode || $keys != ($this->record->key_column_ids ?? []);

        if ($this->identityChanged && $mode === IdentityMode::Key) {
            $this->assertKeyUnique($keys);
        }
    }

    protected function afterSave(): void
    {
        if (! $this->identityChanged) {
            return;
        }

        $messages = app(Backfiller::class)->afterConfigChange($this->record->refresh(), $this->trackedBefore, refreshFromSheet: false);
        Notification::make()->title('Row identity updated')->body(implode(' ', $messages) ?: null)->success()->send();
    }

    /**
     * Checks the new key against the stored rows, so it can be switched without reading the sheet.
     *
     * @param  list<int>  $keyIds
     */
    private function assertKeyUnique(array $keyIds): void
    {
        $fingerprinter = app(Fingerprinter::class);
        $seen = [];

        foreach ($this->record->records()->whereNull('removed_at')->get() as $record) {
            $identity = $fingerprinter->identity($record->data, $keyIds);

            if ($identity === null) {
                throw ValidationException::withMessages(['data.key_column_ids' => 'Stored row '.$record->sheet_row.' has an empty value in the chosen key.']);
            }

            if (isset($seen[$identity])) {
                throw ValidationException::withMessages(['data.key_column_ids' => 'Key "'.$identity.'" is not unique (rows '.$seen[$identity].' and '.$record->sheet_row.').']);
            }

            $seen[$identity] = $record->sheet_row;
        }
    }
}
