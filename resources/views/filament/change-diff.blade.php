@php
    /** @var \App\Models\PendingChange $record */
    $record = $getRecord();
    $headers = $record->sourceTable->columns->where('header', '!=', '_sync_id')->pluck('header', 'id');
    $show = fn ($value) => $value === null || $value === '' ? '∅' : (string) $value;
    $type = $record->type->value;
@endphp
<div class="px-3 py-2 text-sm leading-6">
    @if (in_array($type, ['modified', 'restored']) && count($record->changed_columns ?? []) > 0)
        @foreach ($record->changed_columns as $columnId)
            <div>
                <span class="font-medium">{{ $headers[$columnId] ?? '#'.$columnId }}:</span>
                <span class="text-danger-600 line-through dark:text-danger-400">{{ $show($record->old[$columnId] ?? null) }}</span>
                →
                <span class="text-success-700 dark:text-success-400">{{ $show($record->new[$columnId] ?? null) }}</span>
            </div>
        @endforeach
    @else
        @php $values = $type === 'removed' ? ($record->old ?? []) : ($record->new ?? []); @endphp
        <span class="text-gray-700 dark:text-gray-200">
            {{ $headers->map(fn ($header, $id) => $header.': '.$show($values[$id] ?? null))->take(5)->implode(' · ') }}
        </span>
    @endif
    @if ($record->identity_value && $record->sourceTable->identity_mode->value === 'key')
        <div class="text-xs text-gray-500 dark:text-gray-400">key {{ $record->identity_value }}</div>
    @endif
</div>
