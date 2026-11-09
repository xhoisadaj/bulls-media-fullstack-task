<?php

namespace App\Support;

use App\Models\Mapping;
use App\Models\SourceTable;
use App\Models\Spreadsheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who can see and change what. Admins see every file; editors and viewers only the spreadsheets
 * shared with them, and everything that belongs to those spreadsheets (sheet tables, changes,
 * alerts, sync history, and their rows in the database tables). Without a signed-in user
 * (console, queue, scheduler) nothing is restricted.
 */
final class Access
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function isAdmin(): bool
    {
        return self::user()?->isAdmin() ?? true;
    }

    public static function canEdit(): bool
    {
        return self::user()?->canEdit() ?? true;
    }

    /**
     * The spreadsheet IDs the user can access, or null for all of them.
     *
     * @return list<int>|null
     */
    public static function spreadsheetIds(): ?array
    {
        if (self::isAdmin()) {
            return null;
        }

        return once(fn () => self::user()->spreadsheets()->pluck('spreadsheets.id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * The sheet table IDs the user can access, or null for all of them.
     *
     * @return list<int>|null
     */
    public static function sourceTableIds(): ?array
    {
        $spreadsheets = self::spreadsheetIds();

        if ($spreadsheets === null) {
            return null;
        }

        return once(fn () => SourceTable::query()
            ->whereHas('tab', fn (Builder $q) => $q->whereIn('spreadsheet_id', $spreadsheets))
            ->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public static function canAccessSpreadsheet(int|string|null $id): bool
    {
        $ids = self::spreadsheetIds();

        return $id !== null && ($ids === null || in_array((int) $id, $ids, true));
    }

    /**
     * Limits a query to the user's spreadsheets through the given column (spreadsheet ID).
     */
    public static function whereSpreadsheet(Builder $query, string $column = 'spreadsheet_id'): Builder
    {
        $ids = self::spreadsheetIds();

        return $ids === null ? $query : $query->whereIn($query->qualifyColumn($column), $ids);
    }

    /**
     * Limits a query to the user's sheet tables through the given column (sheet table ID).
     */
    public static function whereSourceTable(Builder $query, string $column = 'source_table_id'): Builder
    {
        $ids = self::sourceTableIds();

        return $ids === null ? $query : $query->whereIn($query->qualifyColumn($column), $ids);
    }

    /**
     * Database tables the user can see: those their sheet tables save into.
     */
    public static function whereManagedTable(Builder $query): Builder
    {
        $ids = self::sourceTableIds();

        return $ids === null ? $query : $query->whereIn($query->qualifyColumn('id'), Mapping::query()->whereIn('source_table_id', $ids)->select('managed_table_id'));
    }

    public static function spreadsheets(): Builder
    {
        return self::whereSpreadsheet(Spreadsheet::query(), 'id');
    }
}
