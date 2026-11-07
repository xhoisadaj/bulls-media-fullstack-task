<div class="ss ss-stack">
    @if (! empty($run->counts))
        <div class="ss-chips">
            @foreach ($run->counts as $key => $value)
                <span class="ss-chip">{{ str_replace('_', ' ', $key) }}: <span class="ss-strong">{{ $value }}</span></span>
            @endforeach
        </div>
    @endif

    @if (! empty($run->errors))
        <div>
            <p class="ss-strong">Notes and skipped rows</p>
            <ul class="ss-list">
                @foreach ($run->errors as $error)
                    <li>
                        @isset($error['table'])<span class="ss-strong">{{ $error['table'] }}:</span>@endisset
                        {{ $error['message'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
