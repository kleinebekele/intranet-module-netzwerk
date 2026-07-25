<?php

namespace Intranet\Modules\Netzwerk\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Netzwerk\Netzwerk;
use Throwable;

/**
 * Daten der Statistik-Seite: Traffic-Verläufe je Port aus network_port_stats
 * (Phase 5). Die Rohdaten kommen im 5-Minuten-Raster; für längere Zeiträume
 * wird direkt in SQL auf gröbere Buckets aggregiert (AVG als Linie, MAX für
 * die "Spitze" in der Zusammenfassung).
 *
 * Die SVG-Geometrie (Pfadpunkte, Gitter, Hover-Ziele) entsteht bewusst HIER
 * und nicht im Blade – die View bleibt dumm und zeichnet nur.
 */
class StatistikDaten
{
    /** Wählbare Zeiträume: Stunden zurück + Bucket-Breite in Minuten. */
    public const ZEITRAEUME = [
        '24h' => ['stunden' => 24, 'bucket' => 5, 'label' => '24 Stunden'],
        '7t' => ['stunden' => 168, 'bucket' => 60, 'label' => '7 Tage'],
        '30t' => ['stunden' => 720, 'bucket' => 360, 'label' => '30 Tage'],
    ];

    // Zeichenfläche des SVG (viewBox) – die View skaliert responsiv.
    private const BREITE = 720;

    private const HOEHE = 190;

    private const RAND = ['links' => 64, 'rechts' => 10, 'oben' => 10, 'unten' => 24];

    /**
     * @return array{knoten: list<object>, gewaehlt: ?object, zeitraum: string,
     *               charts: list<object>, quelle: string}
     */
    public function statistik(?int $knotenId, string $zeitraum): array
    {
        $zeitraum = array_key_exists($zeitraum, self::ZEITRAEUME) ? $zeitraum : '24h';
        ['stunden' => $stunden, 'bucket' => $bucket] = self::ZEITRAEUME[$zeitraum];
        $von = now()->subHours($stunden);

        if ((bool) config('netzwerk.demo', false)) {
            $knoten = collect(DemoDaten::kartenRohdaten()['nodes'])
                ->filter(fn ($n) => $n->status === 'aktiv' && $n->art === 'switch')
                ->values();
            $gewaehlt = $knoten->firstWhere('id', $knotenId) ?? $knoten->first();
            $ports = collect(DemoDaten::kartenRohdaten()['ports'])
                ->filter(fn ($p) => (int) $p->node_id === (int) ($gewaehlt->id ?? 0) && $p->operStatus === 'up');
            $reihen = DemoDaten::portStatistik((int) ($gewaehlt->id ?? 0), $stunden, $bucket);
            $quelle = 'demo';
        } elseif (! Netzwerk::konfiguriert()) {
            return $this->leer('keine Datenquelle konfiguriert');
        } else {
            try {
                $schema = Netzwerk::schema();
                $db = DB::connection(Netzwerk::connection());

                $knoten = collect($db->select(
                    "SELECT DISTINCT n.id, n.name, n.ip FROM {$schema}.network_nodes n
                     JOIN {$schema}.network_ports p ON p.node_id = n.id
                     ORDER BY n.name"
                ))->map(function ($n) {
                    $n->id = (int) $n->id;
                    $n->name = trim((string) $n->name) ?: trim((string) $n->ip);

                    return $n;
                })->values();

                $gewaehlt = $knoten->firstWhere('id', $knotenId) ?? $knoten->first();
                if ($gewaehlt === null) {
                    return $this->leer('noch keine Verlaufsdaten');
                }

                $ports = collect($db->select(
                    "SELECT id, name, operStatus, speedMbit FROM {$schema}.network_ports WHERE node_id = ?",
                    [$gewaehlt->id]
                ));

                // Bucket in SQL: Minuten seit fester Basis, ganzzahlig auf die
                // Bucket-Breite gerundet. $bucket kommt aus der Whitelist oben.
                $basis = '2024-01-01';
                $zeilen = $db->select(
                    "SELECT s.port_id,
                            DATEADD(minute, (DATEDIFF(minute, '{$basis}', s.erfasst_am) / {$bucket}) * {$bucket}, '{$basis}') AS zeitpunkt,
                            AVG(s.inBps) AS inBps, AVG(s.outBps) AS outBps,
                            MAX(s.inBps) AS inMax, MAX(s.outBps) AS outMax
                     FROM {$schema}.network_port_stats s
                     JOIN {$schema}.network_ports p ON p.id = s.port_id
                     WHERE p.node_id = ? AND s.erfasst_am >= ?
                     GROUP BY s.port_id,
                              DATEADD(minute, (DATEDIFF(minute, '{$basis}', s.erfasst_am) / {$bucket}) * {$bucket}, '{$basis}')
                     ORDER BY s.port_id, zeitpunkt",
                    [$gewaehlt->id, $von->format('Y-m-d H:i:s')]
                );

                $reihen = [];
                foreach ($zeilen as $z) {
                    $reihen[(int) $z->port_id][] = [
                        't' => Carbon::parse(trim((string) $z->zeitpunkt)),
                        'in' => $this->zahl($z->inBps),
                        'out' => $this->zahl($z->outBps),
                        'inMax' => $this->zahl($z->inMax),
                        'outMax' => $this->zahl($z->outMax),
                    ];
                }
            } catch (Throwable $e) {
                report($e);

                return $this->leer('Fehler: '.$e->getMessage());
            }

            $quelle = 'mssql';
        }

        // ── Je Port mit Daten ein Chart bauen ─────────────────────────────────
        $portNamen = [];
        foreach ($ports as $p) {
            $portNamen[(int) $p->id] = (object) [
                'name' => trim((string) $p->name) ?: '?',
                'speedMbit' => (int) $p->speedMbit,
            ];
        }

        $charts = [];
        foreach ($reihen as $portId => $punkte) {
            $meta = $portNamen[$portId] ?? null;
            if ($meta === null || $punkte === []) {
                continue;
            }
            $charts[] = $this->chart($meta, $punkte, $von, now(), $bucket);
        }
        usort($charts, fn ($a, $b) => strnatcasecmp($a->port, $b->port));

        return [
            'knoten' => $knoten instanceof \Illuminate\Support\Collection ? $knoten->all() : $knoten,
            'gewaehlt' => $gewaehlt,
            'zeitraum' => $zeitraum,
            'charts' => $charts,
            'quelle' => $quelle,
        ];
    }

    /**
     * Geometrie eines Port-Charts: Pfade (mit Lücken bei Ausfällen), Gitter,
     * Hover-Ziele, Zusammenfassung.
     *
     * @param list<array{t: Carbon, in: ?int, out: ?int, inMax?: ?int, outMax?: ?int}> $punkte
     */
    private function chart(object $meta, array $punkte, Carbon $von, Carbon $bis, int $bucketMinuten): object
    {
        $spanne = max(1, $bis->getTimestamp() - $von->getTimestamp());
        $plotBreite = self::BREITE - self::RAND['links'] - self::RAND['rechts'];
        $plotHoehe = self::HOEHE - self::RAND['oben'] - self::RAND['unten'];

        $maxWert = 0;
        foreach ($punkte as $p) {
            $maxWert = max($maxWert, (int) $p['in'], (int) $p['out']);
        }
        $yMax = $this->schoenesMaximum($maxWert);

        $x = fn (Carbon $t) => self::RAND['links']
            + max(0.0, min(1.0, ($t->getTimestamp() - $von->getTimestamp()) / $spanne)) * $plotBreite;
        $y = fn (int $wert) => self::RAND['oben'] + $plotHoehe - min(1.0, $wert / $yMax) * $plotHoehe;

        // Pfade als Segmente: eine Lücke (Collector-Ausfall) unterbricht die
        // Linie, statt quer hindurch zu interpolieren.
        $segmente = ['in' => [[]], 'out' => [[]]];
        $vorher = null;
        foreach ($punkte as $p) {
            if ($vorher !== null && $p['t']->getTimestamp() - $vorher->getTimestamp() > $bucketMinuten * 150) {
                $segmente['in'][] = [];
                $segmente['out'][] = [];
            }
            foreach (['in', 'out'] as $reihe) {
                if ($p[$reihe] !== null) {
                    $segmente[$reihe][count($segmente[$reihe]) - 1][] =
                        round($x($p['t']), 1).','.round($y((int) $p[$reihe]), 1);
                }
            }
            $vorher = $p['t'];
        }
        $pfade = [];
        foreach (['in', 'out'] as $reihe) {
            $pfade[$reihe] = array_values(array_filter(
                array_map(fn ($s) => implode(' ', $s), $segmente[$reihe]),
                fn ($s) => $s !== '',
            ));
        }

        // Gitter: 0 %, 50 %, 100 % der y-Skala; x-Marken Anfang/Mitte/Ende.
        $gitter = [];
        foreach ([0.0, 0.5, 1.0] as $anteil) {
            $gitter[] = (object) [
                'y' => round(self::RAND['oben'] + $plotHoehe - $anteil * $plotHoehe, 1),
                'label' => $anteil === 0.0 ? '0' : KartenDaten::rateText((int) round($yMax * $anteil)),
            ];
        }
        $mitte = $von->copy()->addSeconds(intdiv($spanne, 2));
        $zeitformat = $bucketMinuten <= 5 ? 'H:i' : 'd.m. H:i';
        $xMarken = [
            (object) ['x' => self::RAND['links'], 'label' => $von->format($zeitformat), 'anker' => 'start'],
            (object) ['x' => self::RAND['links'] + $plotBreite / 2, 'label' => $mitte->format($zeitformat), 'anker' => 'middle'],
            (object) ['x' => self::RAND['links'] + $plotBreite, 'label' => $bis->format($zeitformat), 'anker' => 'end'],
        ];

        // Hover-Ziele: je Bucket ein unsichtbares Rechteck mit Titel.
        $hover = [];
        $halbeBreite = max(2.0, $plotBreite * ($bucketMinuten * 60) / $spanne / 2);
        foreach ($punkte as $p) {
            $hover[] = (object) [
                'x' => round($x($p['t']) - $halbeBreite, 1),
                'breite' => round($halbeBreite * 2, 1),
                'titel' => $p['t']->format('d.m.Y H:i')
                    .' – eingehend '.(KartenDaten::rateText((int) $p['in']) ?? '0')
                    .', ausgehend '.(KartenDaten::rateText((int) $p['out']) ?? '0'),
            ];
        }

        $inWerte = array_values(array_filter(array_column($punkte, 'in'), fn ($w) => $w !== null));
        $outWerte = array_values(array_filter(array_column($punkte, 'out'), fn ($w) => $w !== null));

        return (object) [
            'port' => $meta->name,
            'speedMbit' => $meta->speedMbit,
            'breite' => self::BREITE,
            'hoehe' => self::HOEHE,
            'plot' => (object) [
                'x' => self::RAND['links'],
                'y' => self::RAND['oben'],
                'breite' => $plotBreite,
                'hoehe' => $plotHoehe,
            ],
            'pfade' => $pfade,
            'gitter' => $gitter,
            'xMarken' => $xMarken,
            'hover' => $hover,
            'schnittIn' => KartenDaten::rateText($inWerte === [] ? 0 : (int) (array_sum($inWerte) / count($inWerte))),
            'schnittOut' => KartenDaten::rateText($outWerte === [] ? 0 : (int) (array_sum($outWerte) / count($outWerte))),
            'spitzeIn' => KartenDaten::rateText((int) max([0, ...array_filter(array_column($punkte, 'inMax'), fn ($w) => $w !== null), ...$inWerte])),
            'spitzeOut' => KartenDaten::rateText((int) max([0, ...array_filter(array_column($punkte, 'outMax'), fn ($w) => $w !== null), ...$outWerte])),
        ];
    }

    /** Nächstes "schönes" Achsenmaximum: 1/2/5 × 10^n, mindestens 1 kbit/s. */
    private function schoenesMaximum(int $wert): int
    {
        $wert = max($wert, 1000);
        $zehner = 10 ** (int) floor(log10($wert));
        foreach ([1, 2, 5, 10] as $faktor) {
            if ($faktor * $zehner >= $wert) {
                return $faktor * $zehner;
            }
        }

        return 10 * $zehner;
    }

    private function zahl(mixed $wert): ?int
    {
        $wert = trim((string) $wert);

        return $wert === '' ? null : (int) $wert;
    }

    /** @return array{knoten: list<object>, gewaehlt: ?object, zeitraum: string, charts: list<object>, quelle: string} */
    private function leer(string $grund): array
    {
        return ['knoten' => [], 'gewaehlt' => null, 'zeitraum' => '24h', 'charts' => [], 'quelle' => $grund];
    }
}
