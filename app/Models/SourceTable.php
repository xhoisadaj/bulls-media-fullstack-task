<?php

namespace App\Models;

use App\Enums\ApplyMode;
use App\Enums\EndRule;
use App\Enums\IdentityMode;
use App\Enums\SourceTableStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A header row + data block inside a tab. A tab can hold several.
 *
 * @property list<string> $header_names
 * @property list<int>|null $key_column_ids
 */
class SourceTable extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'header_row' => 'integer',
            'start_column' => 'integer',
            'end_column' => 'integer',
            'end_row' => 'integer',
            'header_names' => 'array',
            'key_column_ids' => 'array',
            'end_rule' => EndRule::class,
            'identity_mode' => IdentityMode::class,
            'apply_mode' => ApplyMode::class,
            'status' => SourceTableStatus::class,
            'is_active' => 'boolean',
        ];
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(Tab::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(SourceColumn::class)->orderBy('position');
    }

    public function records(): HasMany
    {
        return $this->hasMany(SourceRecord::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(Mapping::class);
    }

    public function pendingChanges(): HasMany
    {
        return $this->hasMany(PendingChange::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Source columns that count for change detection: mapped by an active mapping and compare = on.
     *
     * @return Collection<int, SourceColumn>
     */
    public function trackedColumns(): Collection
    {
        $mappedIds = MappingColumn::query()
            ->whereHas('mapping', fn (Builder $q) => $q->where('source_table_id', $this->id)->where('is_active', true))
            ->pluck('source_column_id')
            ->unique();

        return $this->columns()
            ->whereIn('id', $mappedIds)
            ->where('compare', true)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * @return Collection<int, SourceColumn>
     */
    public function keyColumns(): Collection
    {
        $ids = $this->key_column_ids ?? [];

        return $this->columns()->whereIn('id', $ids)->get()->sortBy('id')->values();
    }

    public function label(): string
    {
        $tab = $this->tab;

        return trim(($tab?->spreadsheet?->name ?? '?').' / '.($tab?->name ?? '?').' / '.$this->name);
    }

    public function markBroken(string $reason): void
    {
        $this->forceFill(['status' => SourceTableStatus::Broken, 'status_reason' => $reason])->save();
    }

    public function markOk(): void
    {
        if ($this->status !== SourceTableStatus::Ok) {
            $this->forceFill(['status' => SourceTableStatus::Ok, 'status_reason' => null])->save();
        }
    }
}
