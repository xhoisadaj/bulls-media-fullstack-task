<div class="ss ss-stack">
    @if ($error)
        <p class="ss-error">{{ $error }}</p>
    @elseif (count($rows) === 0)
        <p class="ss-muted">The table has no data rows yet.</p>
    @else
        <div class="ss-scroll">
            <table class="ss-table">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($row as $value)
                                <td>@if ($value === null || $value === '')<span class="ss-null">∅</span>@else{{ $value }}@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="ss-muted">Values are shown as they will be stored (after normalization). ∅ = empty.</p>
    @endif

    @if (count($problems) > 0)
        <div>
            <p class="ss-strong">Rows that will be skipped:</p>
            <ul class="ss-list">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
