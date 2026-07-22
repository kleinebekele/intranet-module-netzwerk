<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Netzwerk · Geräte</h2>
    </x-slot>

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
                <div><span class="text-gray-500">Geräte gesamt:</span> <b>{{ $gesamt }}</b></div>
                <div><span class="text-gray-500">online:</span> <b class="text-green-700">{{ $online }}</b></div>
                <div><span class="text-gray-500">offline:</span> <b class="text-gray-500">{{ $gesamt - $online }}</b></div>
                @if ($aktualisiert)
                    <div class="text-gray-500">Letzter Scan:
                        {{ \Illuminate\Support\Carbon::parse($aktualisiert)->locale('de')->diffForHumans() }}</div>
                @endif
                @if ($quelle !== 'mssql')
                    <div class="text-red-700 font-semibold">⚠ Datenquelle: {{ $quelle }}</div>
                @endif
            </div>

            @forelse ($segmente as $segment => $geraete)
                <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="px-4 sm:px-6 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                        <h3 class="font-semibold text-gray-700 font-mono">{{ $segment }}</h3>
                        <span class="text-sm text-gray-500">
                            {{ collect($geraete)->where('online', true)->count() }} / {{ count($geraete) }} online
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Status</th>
                                    <th class="px-4 py-2 font-medium">IP</th>
                                    <th class="px-4 py-2 font-medium">Hostname</th>
                                    <th class="px-4 py-2 font-medium">Hersteller</th>
                                    <th class="px-4 py-2 font-medium">MAC</th>
                                    <th class="px-4 py-2 font-medium">zuletzt gesehen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($geraete as $g)
                                    <tr class="{{ $g->online ? '' : 'text-gray-400' }}">
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            @if ($g->online)
                                                <span class="inline-flex items-center gap-1.5 text-green-700">
                                                    <span class="h-2 w-2 rounded-full bg-green-500"></span>online</span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 text-gray-400">
                                                    <span class="h-2 w-2 rounded-full bg-gray-300"></span>offline</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 font-mono whitespace-nowrap">{{ $g->ip }}</td>
                                        <td class="px-4 py-2">{{ $g->hostname ?? '—' }}</td>
                                        <td class="px-4 py-2">{{ $g->vendor ?? '—' }}</td>
                                        <td class="px-4 py-2 font-mono text-xs whitespace-nowrap">{{ $g->mac ?? '—' }}</td>
                                        <td class="px-4 py-2 text-gray-500 whitespace-nowrap">
                                            {{ $g->gesehen ? $g->gesehen->locale('de')->diffForHumans() : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-gray-500 space-y-2">
                    <p>Noch keine Geräte erfasst.</p>
                    <p class="text-sm">
                        Dieses Modul liest nur: Ein externer Collector (z. B. ein Raspberry Pi im
                        Netz) schreibt seine Scan-Ergebnisse in die Tabelle
                        <code class="font-mono">network_devices</code> der konfigurierten
                        MSSQL-Datenbank (<code class="font-mono">NETZWERK_DB_*</code> in der
                        <code class="font-mono">.env</code>). Sobald dort Daten liegen, erscheinen sie hier.
                    </p>
                </div>
            @endforelse

        </div>
    </div>
</x-app-layout>
