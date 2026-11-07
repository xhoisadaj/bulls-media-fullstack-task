<ul class="space-y-1 text-sm">
    @forelse ($history as $entry)
        <li class="tabular-nums">
            <span class="text-gray-500 dark:text-gray-400">{{ $entry->created_at?->format('Y-m-d H:i') }}</span>
            · {{ str_replace('_', ' ', $entry->action) }}
            @if ($entry->column_name)<span class="font-mono">{{ $entry->column_name }}</span> ({{ $entry->column_type }})@endif
            · {{ $entry->user?->name ?? 'system' }}
        </li>
    @empty
        <li class="text-gray-500">No schema changes yet.</li>
    @endforelse
</ul>
