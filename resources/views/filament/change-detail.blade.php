@php
    $headers = $change->sourceTable->columns->pluck('header', 'id');
    $changed = $change->changed_columns ?? [];
@endphp
<div class="ss ss-stack">
    @if ($change->matched_by === \App\Sync\RecordMatcher::BY_POSITION)
        <p class="ss-muted">Matched by position: no key proves this is the same row, but it sits in the same place, between the same unchanged rows. Approve if it is the same row; reject and it becomes removed + new.</p>
    @endif
    <div class="ss-scroll">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>Column</th>
                    <th>Stored (approved)</th>
                    <th>Sheet now</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($headers as $id => $header)
                    @php
                        $old = $change->old === null ? null : ($change->old[$id] ?? null);
                        $new = $change->new === null ? null : ($change->new[$id] ?? null);
                    @endphp
                    <tr @class(['ss-hl' => in_array((string) $id, $changed, true)])>
                        <td class="ss-strong">{{ $header }}</td>
                        <td>@if ($change->old === null)<span class="ss-null">—</span>@elseif ($old === null || $old === '')<span class="ss-null">∅</span>@else{{ $old }}@endif</td>
                        <td>@if ($change->new === null)<span class="ss-null">—</span>@elseif ($new === null || $new === '')<span class="ss-null">∅</span>@else{{ $new }}@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="ss-muted">
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
</div>
