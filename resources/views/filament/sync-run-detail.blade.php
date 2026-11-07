<div class="space-y-4 text-sm">
    @if (! empty($run->counts))
        <div class="flex flex-wrap gap-2">
            @foreach ($run->counts as $key => $value)
                <span class="rounded-md bg-gray-100 px-2 py-1 tabular-nums dark:bg-white/10">{{ str_replace('_', ' ', $key) }}: <strong>{{ $value }}</strong></span>
            @endforeach
        </div>
    @endif

    @if (! empty($run->errors))
        <div>
            <p class="mb-1 font-semibold">Notes and skipped rows</p>
            <ul class="list-disc space-y-1 ps-5 text-gray-700 dark:text-gray-200">
                @foreach ($run->errors as $error)
                    <li>
                        @isset($error['table'])<span class="font-medium">{{ $error['table'] }}:</span>@endisset
                        {{ $error['message'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
