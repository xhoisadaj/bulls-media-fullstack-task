<ul class="ss ss-list">
    @forelse ($history as $entry)
        <li>
            <span class="ss-muted">{{ $entry->created_at?->format('Y-m-d H:i') }}</span>
            · {{ str_replace('_', ' ', $entry->action) }}
            @if ($entry->column_name)<span class="ss-mono">{{ $entry->column_name }}</span> ({{ $entry->column_type }})@endif
            · {{ $entry->user?->name ?? 'system' }}
        </li>
    @empty
        <li class="ss-muted">No schema changes yet.</li>
    @endforelse
</ul>
