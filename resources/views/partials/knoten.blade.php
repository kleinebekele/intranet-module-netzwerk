{{-- Ein Knoten der Netzwerkkarte, rekursiv für seine Kinder eingebunden.
     Erwartet: $knoten (object aus KartenDaten), $vonPort/$zuPort (?string,
     Anschluss Richtung Elternknoten), $tiefe (int). --}}
@php
    // Seitenspezifische Klassen (border-l-*), damit sie sich nicht mit dem
    // grauen Rundum-Rahmen um die border-color-Regel streiten.
    $farbe = match (true) {
        $knoten->status === 'entdeckt' => 'border-l-amber-400',
        ! $knoten->online => 'border-l-red-400',
        default => 'border-l-green-500',
    };
    $icon = match ($knoten->art) {
        'ap' => 'wifi',
        'controller', 'firewall' => 'server',
        default => 'network',
    };
    $rate = \Intranet\Modules\Netzwerk\Support\KartenDaten::rateText((int) $knoten->bps);
@endphp

<div class="{{ $tiefe > 0 ? 'mt-3' : '' }}">
    <div class="inline-block max-w-full bg-white border border-gray-200 border-l-4 {{ $farbe }} rounded-lg shadow-sm px-4 py-3">
        @if ($vonPort !== null || $zuPort !== null)
            <div class="text-xs text-gray-400 font-mono mb-1">{{ $vonPort ?? '?' }} ⇄ {{ $zuPort ?? '?' }}</div>
        @endif

        <div class="flex items-center gap-2 flex-wrap">
            <x-module-icon :name="$icon" class="text-xl text-gray-500" />
            <span class="font-semibold text-gray-800">{{ $knoten->name ?? $knoten->ip ?? 'unbenannt' }}</span>
            @if ($knoten->status === 'entdeckt')
                <span class="text-xs font-semibold text-amber-800 bg-amber-100 rounded px-1.5 py-0.5">entdeckt – noch nicht eingebunden</span>
            @elseif (! $knoten->online)
                <span class="text-xs font-semibold text-red-800 bg-red-100 rounded px-1.5 py-0.5">offline</span>
            @endif
        </div>

        <div class="mt-1 text-xs text-gray-500 flex flex-wrap gap-x-4 gap-y-0.5">
            @if ($knoten->ip)<span class="font-mono">{{ $knoten->ip }}</span>@endif
            @if ($knoten->modell)<span>{{ $knoten->modell }}</span>@endif
            @if ($knoten->standort)<span>{{ $knoten->standort }}</span>@endif
            @if ($knoten->portsGesamt > 0)
                <span>{{ $knoten->portsAktiv }}/{{ $knoten->portsGesamt }} Ports aktiv</span>
            @endif
            @if ($rate)<span class="text-gray-700 font-medium">{{ $rate }}</span>@endif
        </div>

        @if (count($knoten->fremde) > 0)
            <div class="mt-2 text-xs text-gray-600">
                @foreach ($knoten->fremde as $fremd)
                    <span class="inline-block bg-gray-100 rounded px-1.5 py-0.5 mr-1 mb-0.5">
                        {{ $fremd['name'] }}@if ($fremd['port']) <span class="text-gray-400 font-mono">({{ $fremd['port'] }})</span>@endif
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    @if (count($knoten->kinder) > 0)
        <div class="ml-5 pl-5 border-l-2 border-gray-200">
            @foreach ($knoten->kinder as $kind)
                @include('netzwerk::partials.knoten', [
                    'knoten' => $kind['knoten'],
                    'vonPort' => $kind['vonPort'],
                    'zuPort' => $kind['zuPort'],
                    'tiefe' => $tiefe + 1,
                ])
            @endforeach
        </div>
    @endif
</div>
