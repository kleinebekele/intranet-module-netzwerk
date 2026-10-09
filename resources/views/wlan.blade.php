<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Netzwerk · WLAN</h2>
    </x-slot>

    @php
        $zeit = fn (?string $wert) => $wert === null ? '–' : \Illuminate\Support\Carbon::parse($wert)->format('d.m.Y H:i');
        $dauer = fn (int $minuten) => $minuten < 60 ? $minuten.' Min.' : intdiv($minuten, 60).' Std. '.($minuten % 60).' Min.';
        // Markierung „ohne Adresse" – inline, unabhängig vom Tailwind-Build.
        $warnung = 'background:var(--color-amber-100, #fef3c7);color:var(--color-amber-800, #92400e)';
    @endphp

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="flex flex-wrap items-center gap-2">
                @foreach ($zeitraeume as $schluessel => [$beschriftung])
                    <a href="{{ request()->fullUrlWithQuery(['zeitraum' => $schluessel]) }}"
                       class="rounded-md border px-3 py-1.5 text-sm {{ $zeitraum === $schluessel ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                        {{ $beschriftung }}
                    </a>
                @endforeach
                @if ($quelle === 'demo')
                    <span class="text-xs text-gray-500">Demo-Daten (NETZWERK_DEMO)</span>
                @endif
            </div>

            @if ($hinweis !== null)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">{{ $hinweis }}</div>
            @endif

            {{-- Ohne Adresse: jetzt + Verlauf --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Eingebucht, aber ohne Adresse</h3>
                    <p class="text-sm text-gray-500">
                        Clients mit 169.254.x.x: Sie hängen am AP, haben aber vom DHCP-Server keine Antwort bekommen.
                        Der Collector schaut alle 5 Minuten nach.
                    </p>
                </div>

                <div>
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Jetzt</h4>
                    @if ($aktuell === [])
                        <p class="text-sm text-gray-500">Gerade kein Client ohne Adresse.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2">Gerät</th>
                                        <th class="px-3 py-2">MAC</th>
                                        <th class="px-3 py-2">IP</th>
                                        <th class="px-3 py-2">WLAN</th>
                                        <th class="px-3 py-2">AP</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($aktuell as $z)
                                        <tr>
                                            <td class="px-3 py-2">{{ $z->hostname ?? '–' }}</td>
                                            <td class="px-3 py-2 font-mono text-xs">{{ $z->mac }}</td>
                                            <td class="px-3 py-2"><span class="rounded px-1.5 py-0.5 font-mono text-xs" style="{{ $warnung }}">{{ $z->ip }}</span></td>
                                            <td class="px-3 py-2">{{ $z->ssid ?? '–' }}</td>
                                            <td class="px-3 py-2">{{ $z->ap_name ?? '–' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div>
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Verlauf im Zeitraum</h4>
                    @if ($verlauf === [])
                        <p class="text-sm text-gray-500">Keine Fälle im Zeitraum.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2">Gerät</th>
                                        <th class="px-3 py-2">MAC</th>
                                        <th class="px-3 py-2">WLAN</th>
                                        <th class="px-3 py-2">AP</th>
                                        <th class="px-3 py-2">von</th>
                                        <th class="px-3 py-2">bis (zuletzt gesehen)</th>
                                        <th class="px-3 py-2">Dauer</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($verlauf as $z)
                                        <tr>
                                            <td class="px-3 py-2">{{ $z->hostname ?? '–' }}</td>
                                            <td class="px-3 py-2 font-mono text-xs">{{ $z->mac }}</td>
                                            <td class="px-3 py-2">{{ $z->ssid ?? '–' }}</td>
                                            <td class="px-3 py-2">{{ $z->ap_name ?? '–' }}</td>
                                            <td class="px-3 py-2 whitespace-nowrap">{{ $zeit($z->erstmals) }}</td>
                                            <td class="px-3 py-2 whitespace-nowrap">{{ $zeit($z->zuletzt) }}</td>
                                            <td class="px-3 py-2 whitespace-nowrap">{{ $z->minuten === 0 ? 'ein Lauf' : $dauer($z->minuten) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Wechsel-Rangliste --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Wer wechselt oft den AP</h3>
                    <p class="text-sm text-gray-500">
                        Gezählt wird, wie oft ein Client zwischen zwei Collector-Läufen (alle 5 Minuten) an einem anderen AP auftaucht.
                        Sprünge dazwischen sieht der Collector nicht – die Zahlen sind Untergrenzen.
                        Viele Wechsel zwischen denselben zwei APs deuten auf einen Ort, an dem beide ähnlich stark sind.
                    </p>
                </div>

                @if ($rangliste === [])
                    <p class="text-sm text-gray-500">Keine AP-Wechsel im Zeitraum.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">Gerät</th>
                                    <th class="px-3 py-2">MAC</th>
                                    <th class="px-3 py-2" title="Aktuelle Adresse aus dem letzten Collector-Lauf; „–" = gerade nicht eingebucht">IP (jetzt)</th>
                                    <th class="px-3 py-2">WLAN</th>
                                    <th class="px-3 py-2 text-right">Wechsel</th>
                                    <th class="px-3 py-2">zwischen</th>
                                    <th class="px-3 py-2">zuletzt</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($rangliste as $z)
                                    <tr class="align-top">
                                        <td class="px-3 py-2">{{ $z->hostname ?? '–' }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $z->mac }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">
                                            @if ($z->ip !== null && str_starts_with($z->ip, '169.254.'))
                                                <span class="rounded px-1.5 py-0.5" style="{{ $warnung }}">{{ $z->ip }}</span>
                                            @else
                                                {{ $z->ip ?? '–' }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">{{ $z->ssid ?? '–' }}</td>
                                        <td class="px-3 py-2 text-right font-medium">{{ $z->anzahl }}</td>
                                        <td class="px-3 py-2">
                                            @foreach (array_slice($z->paare, 0, 3) as $p)
                                                <div>{{ $p['a'] }} ↔ {{ $p['b'] }} <span class="text-gray-500">({{ $p['anzahl'] }}×)</span></div>
                                            @endforeach
                                            @if (count($z->paare) > 3)
                                                <div class="text-xs text-gray-500">+ {{ count($z->paare) - 3 }} weitere</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $zeit($z->zuletzt) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
