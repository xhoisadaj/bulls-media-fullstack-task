@php
    $alerts = \App\Models\StructureAlert::query()->where('status', \App\Enums\AlertStatus::Open)->latest('updated_at')->limit(3)->get();
    $alertCount = \App\Models\StructureAlert::query()->where('status', \App\Enums\AlertStatus::Open)->count();
    $pending = \App\Models\PendingChange::query()->pending()->count();
    $onAlerts = request()->routeIs('filament.admin.resources.structure-alerts.*');
    $onInbox = request()->routeIs('filament.admin.resources.pending-changes.*');
@endphp

@if (($alertCount > 0 && ! $onAlerts) || ($pending > 0 && ! $onInbox))
    <div class="ss ss-banner" role="status">
        @if ($alertCount > 0 && ! $onAlerts)
            <div class="ss-banner-row">
                <span class="ss-banner-icon">⚠</span>
                <div>
                    <p class="ss-strong">{{ $alertCount }} {{ \Illuminate\Support\Str::plural('alert', $alertCount) }} need{{ $alertCount === 1 ? 's' : '' }} your attention</p>
                    <ul class="ss-list">
                        @foreach ($alerts as $alert)
                            <li><span class="ss-strong">{{ $alert->type->getLabel() }}:</span> {{ $alert->message }}</li>
                        @endforeach
                    </ul>
                </div>
                <a class="ss-banner-link" href="{{ \App\Filament\Resources\StructureAlerts\StructureAlertResource::getUrl() }}">Open alerts →</a>
            </div>
        @endif
        @if ($pending > 0 && ! $onInbox)
            <div class="ss-banner-row">
                <span class="ss-banner-icon">●</span>
                <p class="ss-strong">{{ $pending }} {{ \Illuminate\Support\Str::plural('change', $pending) }} from the sheets {{ $pending === 1 ? 'waits' : 'wait' }} for your review</p>
                <a class="ss-banner-link" href="{{ \App\Filament\Resources\PendingChanges\PendingChangeResource::getUrl() }}">Open review inbox →</a>
            </div>
        @endif
    </div>
@endif
