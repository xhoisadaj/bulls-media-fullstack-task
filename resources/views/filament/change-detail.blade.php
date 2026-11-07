@php
    $headers = $change->sourceTable->columns->where('header', '!=', '_sync_id')->pluck('header', 'id');
    $changed = $change->changed_columns ?? [];
    $show = fn ($value) => $value === null || $value === '' ? '∅' : (string) $value;
@endphp
<div class="overflow-x-auto rounded-lg border border-gray-200 text-sm dark:border-white/10">
    <table class="w-full text-left">
        <thead class="bg-gray-50 dark:bg-white/5">
            <tr>
                <th class="px-3 py-2 font-semibold">Column</th>
                <th class="px-3 py-2 font-semibold">Stored (approved)</th>
                <th class="px-3 py-2 font-semibold">Sheet now</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($headers as $id => $header)
                <tr @class(['border-t border-gray-200 dark:border-white/10', 'bg-warning-50 dark:bg-warning-500/10' => in_array((string) $id, $changed, true)])>
                    <td class="px-3 py-1.5 font-medium">{{ $header }}</td>
                    <td class="px-3 py-1.5">{{ $change->old === null ? '—' : $show($change->old[$id] ?? null) }}</td>
                    <td class="px-3 py-1.5">{{ $change->new === null ? '—' : $show($change->new[$id] ?? null) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
    @switch($change->type->value)
        @case('new') A row that isn't stored yet. @break
        @case('modified') Same key, different values. Highlighted columns changed. @break
        @case('removed') This row is no longer in the sheet. Approving sets _removed_at (soft delete); the data stays. @break
        @case('restored') A previously removed row is back in the sheet. @break
    @endswitch
    @if ($change->sourceTable->identity_mode->value === 'content' && in_array($change->type->value, ['new', 'removed']))
        This table has no key, so an edited row shows as one removed and one new row.
    @endif
</p>
