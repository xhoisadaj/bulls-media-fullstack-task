@php
    use App\Google\A1;

    $mark = $preview['mark'] ?? null;
    $inTable = fn (int $r, int $c): bool => $mark !== null
        && $r >= $mark['header_row'] && $r <= $mark['last_row']
        && $c >= $mark['start_column'] && $c <= $mark['end_column'];
@endphp

<div class="ss ss-stack">
    @if ($error)
        <p class="ss-error">Could not load a preview: {{ $error }}</p>
    @elseif ($preview !== null)
        <div>
            <p class="ss-strong">Tab preview</p>
            <p class="ss-muted">
                The selected table is highlighted. Click a header cell to choose where a table starts.
                @if ($preview['total_rows'] > count($preview['rows']) || $preview['total_columns'] > $preview['columns'])
                    Showing {{ count($preview['rows']) }} of {{ $preview['total_rows'] }} rows and {{ $preview['columns'] }} of {{ $preview['total_columns'] }} columns.
                @endif
            </p>
        </div>

        @if ($preview['rows'] === [])
            <p class="ss-muted">This tab is empty.</p>
        @else
            <div
                class="ss-scroll ss-grid-wrap"
                wire:key="tab-preview-{{ $mark ? $mark['header_row'].'-'.$mark['start_column'] : 'none' }}"
                x-data
                x-init="$nextTick(() => {
                    const head = $el.querySelector('.ss-grid-head');
                    if (head) {
                        $el.scrollTop = Math.max(0, head.offsetTop - 60);
                        $el.scrollLeft = Math.max(0, head.offsetLeft - 80);
                    }
                })"
                x-on:click="
                    const td = $event.target.closest('td[data-cell]');
                    if (! td) return;
                    if (td.dataset.option) {
                        $wire.set('data.detected', td.dataset.option);
                    } else {
                        $wire.set('data.manual_cell', td.dataset.cell, false);
                        $wire.set('data.detected', 'manual');
                    }
                "
            >
                <table class="ss-grid">
                    <thead>
                        <tr>
                            <th class="ss-grid-corner"></th>
                            @for ($c = 0; $c < $preview['columns']; $c++)
                                <th>{{ A1::column($c) }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $r => $row)
                            <tr>
                                <th>{{ $r + 1 }}</th>
                                @foreach ($row as $c => $value)
                                    @php
                                        $cell = A1::cell($r, $c);
                                    @endphp
                                    <td
                                        data-cell="{{ $cell }}"
                                        @if (isset($preview['tables'][$cell])) data-option="{{ $preview['tables'][$cell] }}" @endif
                                        @class([
                                            'ss-grid-in' => $inTable($r, $c),
                                            'ss-grid-header' => $mark !== null && $r === $mark['header_row'] && $inTable($r, $c),
                                            'ss-grid-head' => $mark !== null && $r === $mark['header_row'] && $c === $mark['start_column'],
                                            'ss-grid-start' => isset($preview['tables'][$cell]),
                                        ])
                                        title="{{ $cell }}{{ $value !== '' ? ': '.$value : '' }}"
                                    >{{ $value }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
