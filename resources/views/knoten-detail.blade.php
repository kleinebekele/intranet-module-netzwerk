<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Netzwerk · {{ $knoten->name ?? $knoten->ip ?? 'Knoten' }}
        </h2>
    </x-slot>

    @php
        $icon = match ($knoten->art) {
            'ap' => 'wifi',
            'controller', 'firewall' => 'server',
            default => 'network',
        };
    @endphp

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Kopf: Stammdaten des Knotens --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <div class="flex items-center gap-2 flex-wrap">
                    <x-module-icon :name="$icon" class="text-2xl text-gray-500" />
                    <span class="font-semibold text-lg text-gray-800">{{ $knoten->name ?? $knoten->ip ?? 'unbenannt' }}</span>
                    @if ($knoten->status === 'entdeckt')
                        <span class="text-xs font-semibold text-amber-800 bg-amber-100 rounded px-1.5 py-0.5">entdeckt – noch nicht eingebunden</span>
                    @elseif (! $knoten->online)
                        <span class="text-xs font-semibold text-red-800 bg-red-100 rounded px-1.5 py-0.5">offline</span>
                    @else
                        <span class="text-xs font-semibold text-green-800 bg-green-100 rounded px-1.5 py-0.5">online</span>
                    @endif
                    <span class="ml-auto flex items-center gap-4">
                        <a href="{{ route('module.netzwerk.geraet', array_filter([
                                'mac' => $knoten->mac,
                                'ip' => $knoten->ip,
                                'anzeige' => $knoten->name ?? $knoten->ip,
                                'erkannt' => $knoten->pflege->erkannt ? $knoten->pflege->typ : null,
                                'zurueck' => request()->getRequestUri(),
                            ])) }}" class="text-sm text-gray-500 hover:text-gray-700 hover:underline">bearbeiten</a>
                        @if ($knoten->webinterface)
                            <a href="{{ $knoten->webinterface }}" target="_blank" rel="noopener noreferrer" class="text-sm text-gray-500 hover:text-gray-700 hover:underline">Webinterface ↗</a>
                        @endif
                        @if (count($ports) > 0)
                            <a href="{{ route('module.netzwerk.statistik', ['knoten' => $knoten->id]) }}" class="text-sm text-gray-500 hover:text-gray-700 hover:underline">Statistik</a>
                        @endif
                        <a href="{{ route('module.netzwerk.index') }}" class="text-sm text-gray-500 hover:text-gray-700 hover:underline">zur Karte</a>
                    </span>
                </div>
                <div class="mt-2 text-sm text-gray-500 flex flex-wrap gap-x-6 gap-y-1">
                    @if ($knoten->ip)<span class="font-mono">{{ $knoten->ip }}</span>@endif
                    @if ($knoten->pflege->typ)
                        <span>Typ:
                            @if ($knoten->pflege->erkannt)
                                <span class="italic" title="automatisch erkannt – beim Bearbeiten übernehmen">{{ $knoten->pflege->typ }}</span>
                            @else
                                {{ $knoten->pflege->typ }}
                            @endif
                        </span>
                    @endif
                    @if ($knoten->pflege->standort)<span>{{ $knoten->pflege->standort }}</span>@endif
                    @if ($knoten->modell)<span>{{ $knoten->modell }}</span>@endif
                    @if ($knoten->firmware)<span>Firmware {{ $knoten->firmware }}</span>@endif
                    @if ($knoten->standort)<span>{{ $knoten->standort }}</span>@endif
                    @if ($knoten->gesehen)
                        <span>zuletzt gesehen {{ $knoten->gesehen->locale('de')->diffForHumans() }}</span>
                    @endif
                    @if ($quelle === 'demo')
                        <span class="text-indigo-700 font-semibold">Demo-Daten (NETZWERK_DEMO) – nichts hiervon ist echt</span>
                    @endif
                </div>
                @if ($knoten->pflege->info)
                    <p class="mt-2 text-sm text-gray-600 whitespace-pre-line border-l-4 border-gray-200 pl-3">{{ $knoten->pflege->info }}</p>
                @endif
            </div>

            {{-- Portleiste --}}
            @if (count($ports) > 0)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                    <h3 class="font-semibold text-gray-700 mb-1">
                        Ports
                        <span class="font-normal text-sm text-gray-500">
                            – {{ collect($ports)->where('operStatus', 'up')->count() }}/{{ count($ports) }} aktiv
                        </span>
                    </h3>
                    <p class="text-sm text-gray-500 mb-3">
                        Grün = Verbindung aktiv, grau = frei/aus. „Uplink" führt zu einem anderen
                        Infrastruktur-Gerät; darunter stehen die zuletzt an diesem Port gesehenen Geräte.
                        VLANs je Port: „U" = untagged, „T" = tagged.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($ports as $p)
                            @php $aktiv = $p->operStatus === 'up'; @endphp
                            <div class="border border-gray-200 border-l-4 {{ $aktiv ? 'border-l-green-500' : 'border-l-gray-300' }} rounded-lg px-3 py-2 text-sm {{ $aktiv ? '' : 'bg-gray-50 text-gray-400' }}">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono font-semibold {{ $aktiv ? 'text-gray-800' : '' }}">{{ $p->name }}</span>
                                    @if ($p->uplink)
                                        <span class="text-xs font-semibold text-indigo-800 bg-indigo-100 rounded px-1.5 py-0.5">Uplink · {{ $p->uplink }}</span>
                                    @endif
                                </div>
                                <div class="text-xs {{ $aktiv ? 'text-gray-500' : 'text-gray-400' }} mt-0.5 flex flex-wrap gap-x-3">
                                    @if ($aktiv && $p->speedMbit > 0)
                                        <span>{{ $p->speedMbit >= 1000 ? number_format($p->speedMbit / 1000, 1, ',', '.').' Gbit/s' : $p->speedMbit.' Mbit/s' }}</span>
                                    @endif
                                    @if ($p->rate)<span class="text-gray-700 font-medium">{{ $p->rate }}</span>@endif
                                    @if (! $aktiv && $p->adminStatus === 'down')<span>abgeschaltet</span>@endif
                                    @if ($p->vlans !== '')<span title="U = untagged, T = tagged">VLAN {{ $p->vlans }}</span>@endif
                                </div>
                                @if (count($p->geraete) > 0)
                                    <div class="mt-1.5">
                                        @foreach ($p->geraete as $g)
                                            <span class="inline-block text-xs {{ $g->online ? 'bg-gray-100 text-gray-700' : 'bg-gray-50 text-gray-400' }} rounded px-1.5 py-0.5 mr-1 mb-0.5"
                                                  title="{{ $g->ip }}{{ $g->mac ? ' · '.$g->mac : '' }}">
                                                {{ $g->anzeige }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- WLAN-Clients (bei APs) --}}
            @if (count($wlan) > 0)
                <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="px-4 sm:px-6 py-3 border-b border-gray-200 bg-gray-50">
                        <h3 class="font-semibold text-gray-700">Eingebuchte WLAN-Geräte ({{ count($wlan) }})</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Gerät</th>
                                    <th class="px-4 py-2 font-medium">IP</th>
                                    <th class="px-4 py-2 font-medium">MAC</th>
                                    <th class="px-4 py-2 font-medium">SSID</th>
                                    <th class="px-4 py-2 font-medium">zuletzt gesehen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($wlan as $g)
                                    <tr class="{{ $g->online ? '' : 'text-gray-400' }}">
                                        <td class="px-4 py-2">{{ $g->anzeige ?? '—' }}</td>
                                        <td class="px-4 py-2 font-mono whitespace-nowrap">{{ $g->ip }}</td>
                                        <td class="px-4 py-2 font-mono text-xs whitespace-nowrap">{{ $g->mac ?? '—' }}</td>
                                        <td class="px-4 py-2">{{ $g->ssid ?? '—' }}</td>
                                        <td class="px-4 py-2 text-gray-500 whitespace-nowrap">
                                            {{ $g->gesehen ? $g->gesehen->locale('de')->diffForHumans() : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Nachbarn laut Topologie --}}
            @if (count($nachbarn) > 0)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 text-sm">
                    <h3 class="font-semibold text-gray-700 mb-2">Nachbarn</h3>
                    <ul class="space-y-1">
                        @foreach ($nachbarn as $n)
                            <li class="text-gray-700">
                                @if ($n['fremd'])
                                    <span class="font-medium">{{ $n['name'] }}</span>
                                    <span class="text-gray-400 text-xs">(LLDP, kein verwaltetes Gerät)</span>
                                @else
                                    <a href="{{ route('module.netzwerk.knoten', $n['partner']->id) }}" class="font-medium text-gray-800 hover:underline">{{ $n['name'] }}</a>
                                @endif
                                @if ($n['port'])<span class="text-gray-400 font-mono text-xs">über {{ $n['port'] }}</span>@endif
                                @if (! $n['fremd'] && ($n['partnerPort'] ?? null))<span class="text-gray-400 font-mono text-xs">⇄ {{ $n['partnerPort'] }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Geräte ohne (bekannten) Port --}}
            @if (count($weitere) > 0)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 text-sm">
                    <h3 class="font-semibold text-gray-700 mb-2">Weitere zugeordnete Geräte</h3>
                    <p class="text-gray-500 mb-2">Zuletzt an diesem Knoten gesehen, aktueller Port unbekannt.</p>
                    <div>
                        @foreach ($weitere as $g)
                            <span class="inline-block text-xs {{ $g->online ? 'bg-gray-100 text-gray-700' : 'bg-gray-50 text-gray-400' }} rounded px-1.5 py-0.5 mr-1 mb-0.5"
                                  title="{{ $g->ip }}{{ $g->mac ? ' · '.$g->mac : '' }}">
                                {{ $g->anzeige }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (count($ports) === 0 && count($wlan) === 0 && count($nachbarn) === 0)
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-gray-500">
                    Zu diesem Knoten liegen noch keine Detaildaten vor
                    @if ($knoten->status === 'entdeckt')
                        – er wurde per LLDP entdeckt, wird aber noch nicht abgefragt.
                    @endif
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
