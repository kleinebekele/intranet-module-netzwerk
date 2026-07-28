{{-- Ein Knoten der Netzwerkkarte, rekursiv für seine Kinder eingebunden.
     Erwartet: $knoten (object aus KartenDaten), $vonPort/$zuPort (?string,
     Anschluss Richtung Elternknoten), $tiefe (int).
     Auf-/zuklappbar je Knoten; die ersten drei Ebenen starten ausgeklappt. --}}
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
    $hatUnterbau = count($knoten->kinder) > 0 || count($knoten->geraete) > 0;
@endphp

<div class="{{ $tiefe > 0 ? 'mt-3' : '' }}" x-data="{ offen: @js($tiefe < 3) }">
    <div class="inline-block max-w-full bg-white border border-gray-200 border-l-4 {{ $farbe }} rounded-lg shadow-sm px-4 py-3">
        @if ($vonPort !== null || $zuPort !== null)
            <div class="text-xs text-gray-400 font-mono mb-1">{{ $vonPort ?? '?' }} ⇄ {{ $zuPort ?? '?' }}</div>
        @endif

        <div class="flex items-center gap-2 flex-wrap">
            @if ($hatUnterbau)
                <button type="button" @click="offen = !offen"
                        class="inline-flex items-center rounded-md p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                        :title="offen ? 'zuklappen' : 'ausklappen'">
                    <span x-show="offen">▾</span>
                    <span x-show="!offen" style="display: none">▸</span>
                </button>
            @endif
            <x-module-icon :name="$icon" class="text-xl text-gray-500" />
            <a href="{{ route('module.netzwerk.knoten', $knoten->id) }}" class="font-semibold text-gray-800 hover:underline">{{ $knoten->name ?? $knoten->ip ?? 'unbenannt' }}</a>
            @if ($knoten->status === 'entdeckt')
                <span class="text-xs font-semibold text-amber-800 bg-amber-100 rounded px-1.5 py-0.5">entdeckt – noch nicht eingebunden</span>
            @elseif (! $knoten->online)
                <span class="text-xs font-semibold text-red-800 bg-red-100 rounded px-1.5 py-0.5">offline</span>
            @endif
            @if ($hatUnterbau)
                <span class="text-xs text-gray-400" x-show="!offen" style="display: none">
                    {{ implode(' · ', array_filter([
                        count($knoten->kinder) > 0 ? count($knoten->kinder).' Knoten' : null,
                        count($knoten->geraete) > 0 ? count($knoten->geraete).' '.(count($knoten->geraete) === 1 ? 'Gerät' : 'Geräte') : null,
                    ])) }} ausgeblendet
                </span>
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

    @if ($hatUnterbau)
        <div class="ml-5 pl-5 border-l-2 border-gray-200" x-show="offen"
             @if ($tiefe >= 3) style="display: none" @endif>
            @if (count($knoten->geraete) > 0)
                <div class="mt-3" x-data="{ zeigen: true }">
                    <button type="button" @click="zeigen = !zeigen"
                            class="text-xs text-gray-500 hover:text-gray-700">
                        <span x-show="zeigen">▾</span>
                        <span x-show="!zeigen" style="display: none">▸</span>
                        {{ count($knoten->geraete) }} {{ count($knoten->geraete) === 1 ? 'Gerät' : 'Geräte' }}
                    </button>
                    <div class="mt-1" x-show="zeigen">
                        @foreach ($knoten->geraete as $g)
                            <a href="{{ route('module.netzwerk.geraet', array_filter([
                                    'mac' => $g->mac,
                                    'ip' => $g->ip,
                                    'anzeige' => $g->anzeige,
                                    'zurueck' => request()->getRequestUri(),
                                ])) }}"
                               class="inline-block text-xs {{ $g->online ? 'bg-gray-100 text-gray-700' : 'bg-gray-50 text-gray-400' }} rounded px-1.5 py-0.5 mr-1 mb-0.5 hover:underline"
                               title="{{ implode(' · ', array_filter([$g->ip, $g->mac, $g->anschluss])) }}">
                                {{ $g->anzeige }}@if ($g->anschluss) <span class="text-gray-400 font-mono">({{ $g->anschluss }})</span>@endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

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
