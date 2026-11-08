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

    /* Spreadsheet-like tab preview in the table setup wizard */
    .ss-grid-wrap { max-height: 26rem; overflow: auto; position: relative; }
    .ss-grid { border-collapse: separate; border-spacing: 0; font-size: 0.8125rem; }
    .ss-grid th { position: sticky; z-index: 1; padding: 0.2rem 0.5rem; font-weight: 500; font-size: 0.6875rem; color: rgb(107 114 128); background: rgb(243 244 246); border-right: 1px solid rgb(229 231 235); border-bottom: 1px solid rgb(229 231 235); text-align: center; white-space: nowrap; }
    .ss-grid thead th { top: 0; z-index: 2; }
    .ss-grid tbody th { left: 0; min-width: 2.75rem; }
    .ss-grid thead th.ss-grid-corner { left: 0; z-index: 3; }
    .ss-grid td { padding: 0.2rem 0.5rem; min-width: 5rem; max-width: 14rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border-right: 1px solid rgb(229 231 235); border-bottom: 1px solid rgb(229 231 235); cursor: pointer; }
    .ss-grid td:hover { outline: 2px solid rgb(16 185 129 / 0.6); outline-offset: -2px; }
    .ss-grid td.ss-grid-start { box-shadow: inset 3px 0 0 rgb(16 185 129 / 0.5); }
    .ss-grid td.ss-grid-in { background: rgb(209 250 229 / 0.7); }
    .ss-grid td.ss-grid-header { background: rgb(167 243 208); font-weight: 600; }
    .ss-grid td.ss-grid-head { outline: 2px solid rgb(5 150 105); outline-offset: -2px; }
    .dark .ss-grid th { color: rgb(156 163 175); background: rgb(39 39 42); border-color: rgb(255 255 255 / 0.08); }
    .dark .ss-grid td { border-color: rgb(255 255 255 / 0.08); }
    .dark .ss-grid td.ss-grid-in { background: rgb(16 185 129 / 0.12); }
    .dark .ss-grid td.ss-grid-header { background: rgb(16 185 129 / 0.3); }
    .dark .ss-grid td.ss-grid-head { outline-color: rgb(52 211 153); }
</style>
