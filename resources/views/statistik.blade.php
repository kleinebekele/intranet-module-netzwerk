<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Netzwerk · Statistik</h2>
    </x-slot>

    {{-- Serienfarben (validiert: CVD-sicher + Kontrast >= 3:1 auf Weiß):
         eingehend Blau #0284c7, ausgehend Amber #d97706. --}}

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            <form method="get" class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 flex flex-wrap items-center gap-x-6 gap-y-3 text-sm">
                <label class="flex items-center gap-2">
                    <span class="text-gray-500">Gerät:</span>
                    <select name="knoten" onchange="this.form.submit()"
                            class="border-gray-300 rounded-md shadow-sm text-sm">
                        @foreach ($knoten as $k)
                            <option value="{{ $k->id }}" @selected($gewaehlt && $k->id === $gewaehlt->id)>
                                {{ $k->name ?? $k->ip }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2">
                    <span class="text-gray-500">Zeitraum:</span>
                    <select name="zeitraum" onchange="this.form.submit()"
                            class="border-gray-300 rounded-md shadow-sm text-sm">
                        @foreach (\Intranet\Modules\Netzwerk\Support\StatistikDaten::ZEITRAEUME as $key => $z)
                            <option value="{{ $key }}" @selected($key === $zeitraum)>{{ $z['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <span class="inline-flex items-center gap-1.5 text-gray-600">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" style="background:#0284c7"></span> eingehend
                </span>
                <span class="inline-flex items-center gap-1.5 text-gray-600">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" style="background:#d97706"></span> ausgehend
                </span>
                @if ($quelle === 'demo')
                    <span class="text-indigo-700 font-semibold">Demo-Daten (NETZWERK_DEMO) – nichts hiervon ist echt</span>
                @elseif ($quelle !== 'mssql')
                    <span class="text-red-700 font-semibold">⚠ Datenquelle: {{ $quelle }}</span>
                @endif
            </form>

            @forelse ($charts as $c)
                <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 mb-2">
                        <h3 class="font-semibold text-gray-800 font-mono">Port {{ $c->port }}</h3>
                        @if ($c->speedMbit > 0)
                            <span class="text-xs text-gray-500">{{ $c->speedMbit >= 1000 ? number_format($c->speedMbit / 1000, 1, ',', '.').' Gbit/s' : $c->speedMbit.' Mbit/s' }}</span>
                        @endif
                        <span class="text-xs text-gray-500">
                            Ø eingehend <b class="text-gray-700">{{ $c->schnittIn ?? '0' }}</b>
                            · Ø ausgehend <b class="text-gray-700">{{ $c->schnittOut ?? '0' }}</b>
                            · Spitze <b class="text-gray-700">{{ $c->spitzeIn ?? '0' }}</b> / <b class="text-gray-700">{{ $c->spitzeOut ?? '0' }}</b>
                        </span>
                    </div>

                    <svg viewBox="0 0 {{ $c->breite }} {{ $c->hoehe }}" class="w-full h-auto" role="img"
                         aria-label="Traffic-Verlauf Port {{ $c->port }}">
                        {{-- Gitter + y-Beschriftung --}}
                        @foreach ($c->gitter as $g)
                            <line x1="{{ $c->plot->x }}" x2="{{ $c->plot->x + $c->plot->breite }}"
                                  y1="{{ $g->y }}" y2="{{ $g->y }}" stroke="#e5e7eb" stroke-width="1" />
                            <text x="{{ $c->plot->x - 6 }}" y="{{ $g->y + 3 }}" text-anchor="end"
                                  font-size="10" fill="#6b7280">{{ $g->label }}</text>
                        @endforeach

                        {{-- x-Beschriftung --}}
                        @foreach ($c->xMarken as $m)
                            <text x="{{ $m->x }}" y="{{ $c->plot->y + $c->plot->hoehe + 14 }}"
                                  text-anchor="{{ $m->anker }}" font-size="10" fill="#6b7280">{{ $m->label }}</text>
                        @endforeach

                        {{-- Serien: eingehend / ausgehend --}}
                        @foreach ($c->pfade['in'] as $segment)
                            <polyline points="{{ $segment }}" fill="none" stroke="#0284c7"
                                      stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                        @endforeach
                        @foreach ($c->pfade['out'] as $segment)
                            <polyline points="{{ $segment }}" fill="none" stroke="#d97706"
                                      stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                        @endforeach

                        {{-- Hover-Ziele: je Bucket ein unsichtbares Rechteck mit Werten --}}
                        @foreach ($c->hover as $h)
                            <rect x="{{ $h->x }}" y="{{ $c->plot->y }}" width="{{ $h->breite }}"
                                  height="{{ $c->plot->hoehe }}" fill="transparent">
                                <title>{{ $h->titel }}</title>
                            </rect>
                        @endforeach
                    </svg>
                </div>
            @empty
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-gray-500">
                    Für diese Auswahl liegen noch keine Verlaufsdaten vor. Die Historie füllt sich,
                    sobald der Collector mit Phase 5 läuft (eine Zeile je Port und 5-Minuten-Lauf,
                    Aufbewahrung 30 Tage).
                </div>
            @endforelse

        </div>
    </div>
</x-app-layout>
