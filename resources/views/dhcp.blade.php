<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Netzwerk · DHCP</h2>
    </x-slot>

    @php
        // Inline-Farben: unabhängig davon, ob der Tailwind-Build die Klassen kennt.
        // Füllung = Belegung, Rahmen = Pool (durchgezogen: DHCP möglich, gestrichelt: nicht).
        $fuellung = [
            'frei_pool' => ['background:#d1fae5;color:#065f46', 'Frei (DHCP vergibt sie)'],
            'frei_statisch' => ['background:#fff;color:#6b7280', 'Frei für feste IP'],
            'lease' => ['background:#0284c7;color:#fff', 'Lease'],
            'reservierung_aktiv' => ['background:#7c3aed;color:#fff', 'Reservierung, verbunden'],
            'reservierung' => ['background:#ede9fe;color:#5b21b6', 'Reservierung, nicht verbunden'],
            'manuell' => ['background:#f59e0b;color:#fff', 'Manuell belegt (Intranet)'],
            'ping' => ['background:#0f766e;color:#fff', 'Antwortet (feste IP)'],
        ];
        $rahmen = [
            1 => 'border:1px solid #6b7280',
            0 => 'border:1px dashed #cbd5e1',
        ];
        $fuellungVon = fn (array $k) => match ($k['belegung']) {
            'frei' => $k['pool'] ? 'frei_pool' : 'frei_statisch',
            'reservierung' => $k['aktiv'] ? 'reservierung_aktiv' : 'reservierung',
            default => $k['belegung'],
        };
        $grundText = ['pool' => 'DHCP möglich', 'ausgeschlossen' => 'DHCP nicht möglich (ausgeschlossen)', 'ausserhalb' => 'DHCP nicht möglich (außerhalb des Bereichs)'];
    @endphp

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="flex flex-wrap items-center gap-2">
                @foreach ($zeitraeume as $schluessel => [$beschriftung])
                    <a href="{{ request()->fullUrlWithQuery(['zeitraum' => $schluessel, 'page' => null]) }}"
                       class="rounded-md border px-3 py-1.5 text-sm {{ $zeitraum === $schluessel ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                        {{ $beschriftung }}
                    </a>
                @endforeach
            </div>

            @if ($bereiche === [])
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                    Noch keine Daten. Das Skript <code>scripts/dhcp-statistik.ps1</code> auf dem DHCP-Server schickt sie an den
                    Webhook-Eingang, der Task <code>Netzwerk/Dhcp</code> übernimmt sie alle 5 Minuten.
                </div>
            @endif

            @foreach ($bereiche as $x)
                @php
                    $b = $x['b'];
                    $veraltet = $x['gemessen'] === null || $x['gemessen']->lt(now()->subMinutes(45));
                @endphp

                <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">{{ $b->name ?: $b->scope }}</h3>
                            <p class="text-sm text-gray-500">
                                Netz {{ $b->scope }} / {{ $b->maske }} · DHCP-Bereich {{ $b->von }} – {{ $b->bis }}
                                @if ($b->lease_minuten) · Leasedauer {{ intdiv($b->lease_minuten, 60) }} h {{ $b->lease_minuten % 60 ? ($b->lease_minuten % 60).' min' : '' }} @endif
                                @if ($b->server) · Server {{ $b->server }} @endif
                            </p>
                        </div>
                        <p class="text-sm {{ $veraltet ? 'font-medium text-red-600' : 'text-gray-500' }}">
                            Stand {{ $x['gemessen']?->format('d.m.Y H:i') ?? '–' }}
                            @if ($veraltet) – seit über 45 Minuten keine neuen Werte @endif
                        </p>
                    </div>

                    @php $z = $x['zahlen']; @endphp
                    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));">
                        @foreach ([
                            ['DHCP-Pool', $z['pool'], 'color:#111827', 'Adressen, die DHCP vergeben darf'],
                            ['davon frei', $z['pool_frei'], ($z['pool_frei'] !== null && $z['pool_frei'] < 10) ? 'color:#dc2626' : 'color:#047857', 'weder Lease, Reservierung, antwortendes Gerät noch manuell'],
                            ['Leases', $z['lease'], 'color:#0369a1', 'gerade vergeben'],
                            ['Reservierungen', $z['reservierung'], 'color:#6d28d9', 'fest am DHCP-Server'],
                            ['Antwortet', $z['ping'], 'color:#0f766e', 'feste IP, im Ping-Scan online'],
                            ['Manuell belegt', $z['manuell'], 'color:#b45309', 'im Intranet eingetragen'],
                            ['Frei für feste IP', $z['statisch_frei'], 'color:#374151', 'außerhalb des Pools, unbelegt'],
                            ['Pool-Auslastung', $z['pool'] ? number_format(($z['pool'] - $z['pool_frei']) / $z['pool'] * 100, 0, ',', '.').' %' : '–', 'color:#111827', 'belegt / Pool'],
                        ] as [$titel, $wert, $farbe, $hilfe])
                            <div class="rounded-lg border border-gray-200 p-3" title="{{ $hilfe }}">
                                <div class="text-xs text-gray-500">{{ $titel }}</div>
                                <div class="text-2xl font-semibold" style="{{ $farbe }}">{{ $wert ?? '–' }}</div>
                            </div>
                        @endforeach
                    </div>
                    @if ($x['engpass'])
                        <p class="text-sm text-gray-600">
                            Engpass im Zeitraum: {{ $x['engpass']->frei }} frei am {{ \Illuminate\Support\Carbon::parse($x['engpass']->gemessen_am)->format('d.m.Y H:i') }}.
                        </p>
                    @endif

                    {{-- Verlauf freie / belegte Adressen --}}
                    @php
                        $punkte = $x['punkte'];
                        $w = 1000; $h = 220; $rand = 30;
                        $svgFrei = $svgBelegt = '';
                        $yMax = 1; $tMin = $tMax = 0;
                        if (count($punkte) > 1) {
                            $tMin = $punkte[0][0]; $tMax = end($punkte)[0];
                            $yMax = max(1, max(array_map(fn ($p) => max($p[1], $p[2]), $punkte)));
                            $sx = fn ($t) => round($rand + ($t - $tMin) / max(1, $tMax - $tMin) * ($w - $rand - 5), 1);
                            $sy = fn ($v) => round(5 + ($h - 25) * (1 - $v / $yMax), 1);
                            $svgFrei = implode(' ', array_map(fn ($p) => $sx($p[0]).','.$sy($p[1]), $punkte));
                            $svgBelegt = implode(' ', array_map(fn ($p) => $sx($p[0]).','.$sy($p[2]), $punkte));
                        }
                    @endphp
                    <div>
                        <div class="mb-2 flex items-center gap-4 text-sm">
                            <span class="font-medium text-gray-700">Verlauf laut DHCP-Server</span>
                            <span class="inline-flex items-center gap-1"><span style="display:inline-block;width:1.25rem;height:3px;background:#059669"></span> frei</span>
                            <span class="inline-flex items-center gap-1"><span style="display:inline-block;width:1.25rem;height:3px;background:#0284c7"></span> belegt</span>
                        </div>
                        @if (count($punkte) > 1)
                            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full h-56" preserveAspectRatio="none">
                                @foreach ([0, 0.5, 1] as $anteil)
                                    @php $y = round(5 + ($h - 25) * (1 - $anteil), 1); @endphp
                                    <line x1="{{ $rand }}" x2="{{ $w - 5 }}" y1="{{ $y }}" y2="{{ $y }}" stroke="#e5e7eb" stroke-width="1" vector-effect="non-scaling-stroke" />
                                    <text x="{{ $rand - 4 }}" y="{{ $y + 4 }}" text-anchor="end" font-size="11" fill="#6b7280">{{ (int) round($yMax * $anteil) }}</text>
                                @endforeach
                                <polyline points="{{ $svgBelegt }}" fill="none" stroke="#0284c7" stroke-width="2" vector-effect="non-scaling-stroke" />
                                <polyline points="{{ $svgFrei }}" fill="none" stroke="#059669" stroke-width="2" vector-effect="non-scaling-stroke" />
                                <text x="{{ $rand }}" y="{{ $h - 4 }}" font-size="11" fill="#6b7280">{{ \Illuminate\Support\Carbon::createFromTimestamp($tMin, config('app.timezone'))->format('d.m. H:i') }}</text>
                                <text x="{{ $w - 5 }}" y="{{ $h - 4 }}" text-anchor="end" font-size="11" fill="#6b7280">{{ \Illuminate\Support\Carbon::createFromTimestamp($tMax, config('app.timezone'))->format('d.m. H:i') }}</text>
                            </svg>
                        @else
                            <p class="text-sm text-gray-500">Für den Verlauf braucht es mindestens zwei Messungen im Zeitraum.</p>
                        @endif
                    </div>

                    {{-- Adresskarte: ein Kästchen je Adresse. Klick öffnet die Details darunter. --}}
                    @if ($x['karte'])
                        <div x-data="{ wahl: null }">
                            <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
                                <span class="font-medium text-sm text-gray-700">Adressen jetzt</span>
                                <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm" style="{{ $rahmen[1] }}"></span>DHCP möglich</span>
                                <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm" style="{{ $rahmen[0] }}"></span>DHCP nicht möglich</span>
                                <span class="text-gray-300">|</span>
                                @foreach ($fuellung as [$stil, $text])
                                    <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm border border-gray-300" style="{{ $stil }}"></span>{{ $text }}</span>
                                @endforeach
                                <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-white" style="box-shadow:0 0 0 2px #dc2626"></span>Manuell belegt, aber vom DHCP vergeben</span>
                            </div>
                            <div class="grid gap-1" style="grid-template-columns: repeat(auto-fill, minmax(2.6rem, 1fr));">
                                @foreach ($x['karte'] as $k)
                                    @php
                                        $f = $fuellungVon($k);
                                        $konflikt = $k['manuell'] && $k['belegung'] !== 'manuell';
                                        $titel = $k['ip'].' – '.$fuellung[$f][1].' · '.$grundText[$k['grund']];
                                        if ($k['manuell']) $titel .= "\n".$k['manuell']['bezeichnung'];
                                        if ($k['geraet']) $titel .= "\n".$k['geraet'];
                                        if ($k['mac']) $titel .= "\n".$k['mac'];
                                    @endphp
                                    <button type="button" @click="wahl = {{ \Illuminate\Support\Js::from($k + ['text' => $fuellung[$f][1], 'grundText' => $grundText[$k['grund']]]) }}"
                                            title="{{ $titel }}"
                                            :style="wahl && wahl.ip === '{{ $k['ip'] }}' ? { outline: '2px solid #4f46e5', outlineOffset: '1px' } : {}"
                                            class="rounded px-1 py-1 text-center font-mono text-xs"
                                            style="{{ $fuellung[$f][0] }};{{ $rahmen[(int) $k['pool']] }}{{ $konflikt ? ';box-shadow:0 0 0 2px #dc2626' : '' }}">{{ $k['letztes'] }}</button>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-gray-500" x-show="!wahl">Klick auf ein Kästchen zeigt die Details; dort lässt sich eine Adresse manuell belegen.</p>

                            <div x-show="wahl" style="display: none;" class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                                <template x-if="wahl">
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div class="space-y-1 text-sm">
                                            <div class="flex items-center justify-between">
                                                <span class="font-mono text-base font-semibold" x-text="wahl.ip"></span>
                                                <button type="button" @click="wahl = null" class="text-gray-500 hover:text-gray-700">✕</button>
                                            </div>
                                            <div><span class="text-gray-500">Pool:</span> <span x-text="wahl.grundText"></span></div>
                                            <div><span class="text-gray-500">Belegung:</span> <span x-text="wahl.text"></span></div>
                                            <div x-show="wahl.geraet"><span class="text-gray-500">Gerät:</span> <span x-text="wahl.geraet"></span></div>
                                            <div x-show="wahl.mac"><span class="text-gray-500">MAC:</span> <span class="font-mono" x-text="wahl.mac"></span></div>
                                            <div x-show="wahl.hersteller"><span class="text-gray-500">Hersteller:</span> <span x-text="wahl.hersteller"></span></div>
                                            <div x-show="wahl.ping"><span class="text-gray-500">Ping-Scan:</span> <span x-text="wahl.ping"></span></div>
                                            <div x-show="wahl.typ"><span class="text-gray-500">Typ:</span> <span x-text="wahl.typ"></span></div>
                                            <div x-show="wahl.standort"><span class="text-gray-500">Standort:</span> <span x-text="wahl.standort"></span></div>
                                            <div x-show="wahl.info"><span class="text-gray-500">Info:</span> <span x-text="wahl.info"></span></div>
                                            <div x-show="wahl.beschreibung"><span class="text-gray-500">Beschreibung:</span> <span x-text="wahl.beschreibung"></span></div>
                                            <div x-show="wahl.seit"><span class="text-gray-500">Lease seit:</span> <span x-text="wahl.seit"></span></div>
                                            <div x-show="wahl.ablauf"><span class="text-gray-500">Lease bis:</span> <span x-text="wahl.ablauf"></span></div>
                                            <div x-show="wahl.manuell && wahl.belegung !== 'manuell'" class="font-medium text-red-700">
                                                Manuell belegt, aber der DHCP-Server hat die Adresse selbst vergeben – eine der beiden Angaben stimmt nicht.
                                            </div>
                                            <a :href="'{{ request()->fullUrlWithQuery(['suche' => '__IP__', 'page' => null]) }}'.replace('__IP__', encodeURIComponent(wahl.ip)) + '#belegungen'"
                                               class="inline-block pt-1 text-indigo-700 hover:underline">Wer hatte diese Adresse?</a>
                                            <a :href="wahl.bearbeiten" class="ml-4 inline-block pt-1 text-indigo-700 hover:underline">Typ, Standort, Info bearbeiten</a>
                                        </div>

                                        <div class="space-y-2">
                                            <form method="POST" action="{{ route('module.netzwerk.dhcp.manuell') }}" class="space-y-2">
                                                @csrf
                                                <input type="hidden" name="scope" value="{{ $b->scope }}">
                                                <input type="hidden" name="zeitraum" value="{{ $zeitraum }}">
                                                <input type="hidden" name="ip" :value="wahl.ip">
                                                <div class="text-sm font-medium text-gray-700" x-text="wahl.manuell ? 'Manuelle Belegung' : 'Manuell belegen (Gerät mit fester IP)'"></div>
                                                <input type="text" name="bezeichnung" required maxlength="255" placeholder="Gerät, z. B. Drucker Lehrerzimmer"
                                                       :value="wahl.manuell ? wahl.manuell.bezeichnung : ''"
                                                       class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <textarea name="notiz" rows="2" maxlength="2000" placeholder="Notiz (optional)"
                                                          x-text="wahl.manuell ? (wahl.manuell.notiz || '') : ''"
                                                          class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                                                <div class="flex items-center gap-2">
                                                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Speichern</button>
                                                    <span x-show="wahl.manuell" class="text-xs text-gray-500" x-text="wahl.manuell ? 'zuletzt geändert ' + wahl.manuell.am : ''"></span>
                                                </div>
                                            </form>
                                            <form method="POST" action="{{ route('module.netzwerk.dhcp.manuell.entfernen') }}" x-show="wahl.manuell">
                                                @csrf
                                                <input type="hidden" name="scope" value="{{ $b->scope }}">
                                                <input type="hidden" name="zeitraum" value="{{ $zeitraum }}">
                                                <input type="hidden" name="ip" :value="wahl.ip">
                                                <button class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Manuelle Belegung aufheben</button>
                                            </form>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($belegungen !== null)
                <div id="belegungen" class="rounded-xl border border-gray-200 bg-white p-6 space-y-4">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Wer hatte wann welche Adresse</h3>
                            <p class="text-sm text-gray-500">Eine Zeile je ununterbrochener Belegung im gewählten Zeitraum.</p>
                        </div>
                        <form method="GET" class="flex gap-2">
                            <input type="hidden" name="zeitraum" value="{{ $zeitraum }}">
                            <input type="text" name="suche" value="{{ $suche }}" placeholder="IP, MAC oder Gerätename"
                                   class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Suchen</button>
                            @if ($suche !== '')
                                <a href="{{ request()->fullUrlWithQuery(['suche' => null, 'page' => null]) }}#belegungen" class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Alle</a>
                            @endif
                        </form>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">IP</th>
                                    <th class="px-3 py-2">Gerät</th>
                                    <th class="px-3 py-2">MAC</th>
                                    <th class="px-3 py-2">Art</th>
                                    <th class="px-3 py-2">von</th>
                                    <th class="px-3 py-2">bis (zuletzt gesehen)</th>
                                    <th class="px-3 py-2">Dauer</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($belegungen as $z)
                                    @php
                                        $von = \Illuminate\Support\Carbon::parse($z->erstmals);
                                        $bis = \Illuminate\Support\Carbon::parse($z->zuletzt);
                                        $jetzt = collect($bereiche)->contains(fn ($x) => $x['b']->scope === $z->scope && $x['gemessen']?->equalTo($bis));
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-2 font-mono">{{ $z->ip }}</td>
                                        <td class="px-3 py-2">{{ $z->geraet ?: '–' }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $z->mac }}</td>
                                        <td class="px-3 py-2">{{ $z->reserviert ? 'Reservierung' : 'Lease' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $von->format('d.m.Y H:i') }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            @if ($jetzt) <span class="rounded px-1.5 py-0.5 text-xs font-medium" style="background:#e0f2fe;color:#075985">jetzt</span> @else {{ $bis->format('d.m.Y H:i') }} @endif
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $von->equalTo($bis) ? '–' : $von->diffForHumans($bis, true) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">Keine Belegungen im Zeitraum.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $belegungen->fragment('belegungen')->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
