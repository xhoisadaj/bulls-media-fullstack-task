{{-- Styles for the app's own views (Filament ships only the CSS classes it uses itself). --}}
<style>
    .ss { font-size: 0.875rem; line-height: 1.45; color: inherit; }
    .ss-stack > * + * { margin-top: 0.75rem; }
    .ss-scroll { overflow-x: auto; border: 1px solid rgb(229 231 235); border-radius: 0.5rem; }
    .dark .ss-scroll { border-color: rgb(255 255 255 / 0.1); }
    .ss-table { width: 100%; border-collapse: collapse; text-align: left; }
    .ss-table th { padding: 0.5rem 0.75rem; font-weight: 600; font-size: 0.75rem; white-space: nowrap; background: rgb(249 250 251); border-bottom: 1px solid rgb(229 231 235); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .dark .ss-table th { background: rgb(255 255 255 / 0.05); border-bottom-color: rgb(255 255 255 / 0.1); }
    .ss-table td { padding: 0.45rem 0.75rem; vertical-align: top; border-top: 1px solid rgb(229 231 235); min-width: 6rem; max-width: 18rem; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
    .dark .ss-table td { border-top-color: rgb(255 255 255 / 0.08); }
    .ss-table tr:first-child td { border-top: 0; }
    .ss-table tr.ss-hl td { background: rgb(254 243 199 / 0.6); }
    .dark .ss-table tr.ss-hl td { background: rgb(245 158 11 / 0.12); }
    .ss-null { color: rgb(156 163 175); }
    .ss-muted { color: rgb(107 114 128); font-size: 0.75rem; }
    .dark .ss-muted { color: rgb(156 163 175); }
    .ss-old { color: rgb(220 38 38); text-decoration: line-through; }
    .dark .ss-old { color: rgb(248 113 113); }
    .ss-new { color: rgb(21 128 61); }
    .dark .ss-new { color: rgb(74 222 128); }
    .ss-error { color: rgb(220 38 38); }
    .dark .ss-error { color: rgb(248 113 113); }
    .ss-strong { font-weight: 600; }
    .ss-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .ss-list { list-style: disc; padding-left: 1.25rem; }
    .ss-list > li + li { margin-top: 0.25rem; }
    .ss-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .ss-chip { border-radius: 0.375rem; padding: 0.15rem 0.5rem; background: rgb(243 244 246); font-variant-numeric: tabular-nums; }
    .dark .ss-chip { background: rgb(255 255 255 / 0.08); }
    .ss-cell { padding: 0.5rem 0.75rem; }
</style>
