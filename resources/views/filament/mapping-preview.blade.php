<div class="space-y-3 text-sm">
    @if ($error)
        <p class="text-danger-600 dark:text-danger-400">{{ $error }}</p>
    @elseif (count($rows) === 0)
        <p class="text-gray-500 dark:text-gray-400">The table has no data rows yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        @foreach ($columns as $column)
                            <th class="px-3 py-2 font-mono text-xs font-semibold">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            @foreach ($row as $value)
                                <td class="px-3 py-1.5 tabular-nums">{{ $value ?? '∅' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (count($problems) > 0)
        <div>
            <p class="font-semibold">Rows that will be skipped:</p>
            <ul class="list-disc ps-5 text-gray-600 dark:text-gray-300">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
