<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Netzwerk · Karte</h2>
    </x-slot>

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
                <div><span class="text-gray-500">Infrastruktur-Geräte:</span> <b>{{ $gesamt }}</b></div>
                <div><span class="text-gray-500">online:</span> <b class="text-green-700">{{ $online }}</b></div>
                @if ($entdeckt > 0)
                    <div><span class="text-gray-500">entdeckt:</span> <b class="text-amber-700">{{ $entdeckt }}</b></div>
                @endif
                @if ($aktualisiert)
                    <div class="text-gray-500">Stand:
                        {{ \Illuminate\Support\Carbon::parse($aktualisiert)->locale('de')->diffForHumans() }}</div>
                @endif
                @if ($quelle === 'demo')
                    <div class="text-indigo-700 font-semibold">Demo-Daten (NETZWERK_DEMO) – nichts hiervon ist echt</div>
                @elseif ($quelle !== 'mssql')
                    <div class="text-red-700 font-semibold">⚠ Datenquelle: {{ $quelle }}</div>
                @endif
            </div>

            @if ($entdeckt > 0)
                <div class="bg-amber-50 border border-amber-200 sm:rounded-lg p-4 sm:p-6 text-sm text-amber-900">
                    <p class="font-semibold mb-1">
                        {{ $entdeckt === 1 ? 'Ein Gerät wurde entdeckt, ist aber' : $entdeckt.' Geräte wurden entdeckt, sind aber' }}
                        noch nicht eingebunden
                    </p>
                    <p>
                        Der Collector hat per LLDP Nachbarn gefunden, die er (noch) nicht abfragen darf –
                        in der Karte amber markiert. Zum Einbinden auf dem jeweiligen Gerät den
                        SNMPv3-Benutzer <code class="font-mono">netmon</code> anlegen (Read-Only, authPriv,
                        SHA + DES, wie auf den übrigen Switches) und die Konfiguration speichern
                        („Save Config"). Beim nächsten Lauf bindet der Collector das Gerät automatisch ein.
                    </p>
                </div>
            @endif

            @forelse ($wurzeln as $wurzel)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 overflow-x-auto">
                    @include('netzwerk::partials.knoten', [
                        'knoten' => $wurzel,
                        'vonPort' => null,
                        'zuPort' => null,
                        'tiefe' => 0,
                    ])
                </div>
            @empty
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-gray-500">
                    Noch keine Infrastruktur erfasst. Sobald der Collector seine Topologie-Läufe in
                    <code class="font-mono">network_nodes</code> / <code class="font-mono">network_links</code>
                    schreibt, entsteht hier die Karte.
                </div>
            @endforelse

            @if (count($quer) > 0)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 text-sm">
                    <h3 class="font-semibold text-gray-700 mb-2">Querverbindungen</h3>
                    <p class="text-gray-500 mb-3">
                        Diese Verbindungen passen nicht in den Baum (Ring oder Redundanz) – sie existieren
                        zusätzlich zu den oben gezeigten Wegen.
                    </p>
                    <ul class="space-y-1">
                        @foreach ($quer as $q)
                            <li class="text-gray-700">
                                <span class="font-medium">{{ $q['von']->name ?? $q['von']->ip }}</span>
                                @if ($q['vonPort'])<span class="text-gray-400 font-mono text-xs">({{ $q['vonPort'] }})</span>@endif
                                ⇄
                                <span class="font-medium">{{ $q['zu']->name ?? $q['zu']->ip }}</span>
                                @if ($q['zuPort'])<span class="text-gray-400 font-mono text-xs">({{ $q['zuPort'] }})</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
