@php
    /** @var \App\Models\PendingChange $record */
    $record = $getRecord();
    $headers = $record->sourceTable->columns->pluck('header', 'id');
    $show = fn ($value) => $value === null || $value === '' ? '∅' : \Illuminate\Support\Str::limit((string) $value, 60);
    $type = $record->type->value;
@endphp
<div class="ss ss-cell">
    @if (in_array($type, ['modified', 'restored']) && count($record->changed_columns ?? []) > 0)
        @foreach ($record->changed_columns as $columnId)
            <div>
                <span class="ss-strong">{{ $headers[$columnId] ?? '#'.$columnId }}:</span>
                <span class="ss-old">{{ $show($record->old[$columnId] ?? null) }}</span>
                →
                <span class="ss-new">{{ $show($record->new[$columnId] ?? null) }}</span>
            </div>
        @endforeach
    @else
        @php $values = $type === 'removed' ? ($record->old ?? []) : ($record->new ?? []); @endphp
        <span>{{ $headers->map(fn ($header, $id) => $header.': '.$show($values[$id] ?? null))->take(4)->implode(' · ') }}</span>
    @endif
    @if ($record->identity_value && $record->sourceTable->identity_mode->value === 'key')
        <div class="ss-muted">key {{ $record->identity_value }}</div>
    @endif
</div>
