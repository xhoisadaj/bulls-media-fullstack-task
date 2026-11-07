<?php

namespace App\Models;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StructureAlert extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'status' => AlertStatus::class,
            'details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function sourceTable(): BelongsTo
    {
        return $this->belongsTo(SourceTable::class);
    }

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', AlertStatus::Open);
    }

    /**
     * Opens an alert unless an identical open one already exists, so daily syncs don't pile up duplicates.
     *
     * @param  array<string, mixed>  $details
     */
    public static function raise(AlertType $type, string $message, ?SourceTable $sourceTable = null, ?Spreadsheet $spreadsheet = null, array $details = []): self
    {
        $spreadsheet ??= $sourceTable?->tab?->spreadsheet;

        $existing = static::query()
            ->open()
            ->where('type', $type)
            ->where('source_table_id', $sourceTable?->id)
            ->where('spreadsheet_id', $spreadsheet?->id)
            ->where('message', $message)
            ->first();

        if ($existing !== null) {
            $existing->touch();

            return $existing;
        }

        return static::query()->create([
            'type' => $type,
            'message' => mb_substr($message, 0, 500),
            'source_table_id' => $sourceTable?->id,
            'spreadsheet_id' => $spreadsheet?->id,
            'details' => $details,
            'status' => AlertStatus::Open,
        ]);
    }

    /**
     * Resolves open alerts of a type once the problem is gone.
     */
    public static function resolve(AlertType $type, ?SourceTable $sourceTable = null, ?Spreadsheet $spreadsheet = null): void
    {
        static::query()
            ->open()
            ->where('type', $type)
            ->when($sourceTable, fn (Builder $q) => $q->where('source_table_id', $sourceTable->id))
            ->when($spreadsheet && ! $sourceTable, fn (Builder $q) => $q->where('spreadsheet_id', $spreadsheet->id))
            ->update(['status' => AlertStatus::Resolved, 'resolved_at' => now()]);
    }
}
