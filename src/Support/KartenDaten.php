<?php

namespace Intranet\Modules\Netzwerk\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Netzwerk\Netzwerk;
use Throwable;

/**
 * Baut aus network_nodes / network_links / network_ports den Baum der
 * Netzwerkkarte.
 *
 * LLDP liefert jede Verbindung doppelt (der Master meldet den Werkstatt-Switch,
 * der Werkstatt-Switch meldet den Master). Hier wird daraus ein ungerichteter
 * Graph mit EINER Kante je Knotenpaar und ab der Wurzel per Breitensuche ein
 * Baum. Kanten, die dabei übrig bleiben (Ring/Redundanz), gehen nicht verloren,
 * sondern erscheinen als Querverbindungen unter dem Baum.
 *
 * "online" wird wie überall im Modul beim LESEN aus lastSeen berechnet – fällt
 * der Collector aus, zeigt die Karte ehrlich offline statt ewig grün.
 */
class KartenDaten
{
    /**
     * @return array{wurzeln: list<object>, quer: list<array<string, mixed>>, gesamt: int,
     *               online: int, entdeckt: int, quelle: string, aktualisiert: ?string}
     */
    public function karte(): array
    {
        if ((bool) config('netzwerk.demo', false)) {
            ['nodes' => $nodes, 'links' => $links, 'ports' => $ports] = DemoDaten::kartenRohdaten();
            $quelle = 'demo';
        } elseif (! Netzwerk::konfiguriert()) {
            return $this->leer('keine Datenquelle konfiguriert');
        } else {
            try {
                $schema = Netzwerk::schema();
                $db = DB::connection(Netzwerk::connection());

                $nodes = $db->select("SELECT id, art, name, ip, modell, firmware, standort, status, lastSeen FROM {$schema}.network_nodes");
                $links = $db->select("SELECT von_node_id, von_port, zu_node_id, zu_port, zu_fremd_mac, zu_fremd_name FROM {$schema}.network_links");
                $ports = $db->select("SELECT node_id, operStatus, inBps, outBps FROM {$schema}.network_ports");
            } catch (Throwable $e) {
                report($e);

                return $this->leer('Fehler: '.$e->getMessage());
            }

            $quelle = 'mssql';
        }

        $grenze = now()->subMinutes((int) config('netzwerk.offline_ab_minuten', 15));
        $aktualisiert = null;

        // ── Knoten normalisieren (ODBC: NULL kommt als "", Zahlen als String) ──
        /** @var array<int, object> $beiId */
        $beiId = [];
        foreach ($nodes as $n) {
            $n->id = (int) $n->id;
            foreach (['art', 'name', 'ip', 'modell', 'firmware', 'standort', 'status'] as $feld) {
                $n->$feld = $this->leerZuNull($n->$feld ?? null);
            }
            $n->art ??= 'switch';
            $n->status ??= 'entdeckt';

            $gesehenRoh = trim((string) ($n->lastSeen ?? ''));
            $n->gesehen = $gesehenRoh === '' ? null : Carbon::parse($gesehenRoh);
            $n->online = $n->gesehen !== null && $n->gesehen->greaterThanOrEqualTo($grenze);

            $n->kinder = [];       // [['knoten' => object, 'vonPort' => ?string, 'zuPort' => ?string], …]
            $n->fremde = [];       // LLDP-Nachbarn ohne eigenen Node (PCs hinter unmanaged Verteilern)
            $n->portsGesamt = 0;
            $n->portsAktiv = 0;
            $n->bps = 0;           // Summe der aktuellen Raten aller aktiven Ports (bit/s)

            if ($n->gesehen !== null && ($aktualisiert === null || $n->gesehen->greaterThan($aktualisiert))) {
                $aktualisiert = $n->gesehen;
            }

            $beiId[$n->id] = $n;
        }

        // ── Port-Zahlen je Knoten aufsummieren ────────────────────────────────
        foreach ($ports as $p) {
            $node = $beiId[(int) $p->node_id] ?? null;
            if ($node === null) {
                continue;
            }
            $node->portsGesamt++;
            if (trim((string) $p->operStatus) === 'up') {
                $node->portsAktiv++;
                $node->bps += (int) $p->inBps + (int) $p->outBps;
            }
        }

        // ── Kanten deduplizieren: eine je Knotenpaar, Ports aus beiden Sichten ─
        /** @var array<string, array{a: int, b: int, portA: ?string, portB: ?string, benutzt: bool}> $kanten */
        $kanten = [];
        foreach ($links as $l) {
            $von = (int) $l->von_node_id;
            $zuRoh = trim((string) ($l->zu_node_id ?? ''));

            if ($zuRoh === '') {
                // Fremd-Nachbar: sichtbar per LLDP, aber kein eigener Node.
                $node = $beiId[$von] ?? null;
                if ($node !== null) {
                    $mac = $this->leerZuNull($l->zu_fremd_mac);
                    $name = $this->leerZuNull($l->zu_fremd_name) ?? $mac ?? 'unbekannt';
                    $port = $this->leerZuNull($l->von_port);
                    $node->fremde[$name.'|'.$port] = ['name' => $name, 'mac' => $mac, 'port' => $port];
                }

                continue;
            }

            $zu = (int) $zuRoh;
            if ($von === $zu || ! isset($beiId[$von], $beiId[$zu])) {
                continue;
            }

            [$a, $b] = $von < $zu ? [$von, $zu] : [$zu, $von];
            $key = $a.'-'.$b;
            $kanten[$key] ??= ['a' => $a, 'b' => $b, 'portA' => null, 'portB' => null, 'benutzt' => false];

            if ($von === $a) {
                $kanten[$key]['portA'] ??= $this->leerZuNull($l->von_port);
                $kanten[$key]['portB'] ??= $this->leerZuNull($l->zu_port);
            } else {
                $kanten[$key]['portB'] ??= $this->leerZuNull($l->von_port);
                $kanten[$key]['portA'] ??= $this->leerZuNull($l->zu_port);
            }
        }

        /** @var array<int, list<string>> $adjazenz */
        $adjazenz = [];
        foreach ($kanten as $key => $k) {
            $adjazenz[$k['a']][] = $key;
            $adjazenz[$k['b']][] = $key;
        }

        // ── Breitensuche: aus dem Graphen wird ein Baum (je Komponente einer) ──
        $besucht = [];
        $wurzeln = [];
        while (count($besucht) < count($beiId)) {
            $wurzel = $this->wurzelWaehlen($beiId, $adjazenz, $besucht);
            $besucht[$wurzel->id] = true;
            $reihe = [$wurzel];

            while ($reihe !== []) {
                $node = array_shift($reihe);

                foreach ($adjazenz[$node->id] ?? [] as $key) {
                    $nachbarId = $kanten[$key]['a'] === $node->id ? $kanten[$key]['b'] : $kanten[$key]['a'];
                    if (isset($besucht[$nachbarId])) {
                        continue;
                    }

                    $kanten[$key]['benutzt'] = true;
                    $besucht[$nachbarId] = true;
                    $nachbar = $beiId[$nachbarId];

                    $vonIstA = $kanten[$key]['a'] === $node->id;
                    $node->kinder[] = [
                        'knoten' => $nachbar,
                        'vonPort' => $vonIstA ? $kanten[$key]['portA'] : $kanten[$key]['portB'],
                        'zuPort' => $vonIstA ? $kanten[$key]['portB'] : $kanten[$key]['portA'],
                    ];
                    $reihe[] = $nachbar;
                }

                usort($node->kinder, fn ($x, $y) => strnatcasecmp(
                    $x['knoten']->name ?? $x['knoten']->ip ?? '',
                    $y['knoten']->name ?? $y['knoten']->ip ?? '',
                ));
                $node->fremde = array_values($node->fremde);
            }

            $wurzeln[] = $wurzel;
        }

        // ── Übrig gebliebene Kanten = Ring/Redundanz, nicht verschweigen ──────
        $quer = [];
        foreach ($kanten as $k) {
            if ($k['benutzt']) {
                continue;
            }
            $quer[] = [
                'von' => $beiId[$k['a']],
                'zu' => $beiId[$k['b']],
                'vonPort' => $k['portA'],
                'zuPort' => $k['portB'],
            ];
        }

        return [
            'wurzeln' => $wurzeln,
            'quer' => $quer,
            'gesamt' => count($beiId),
            'online' => count(array_filter($beiId, fn ($n) => $n->online)),
            'entdeckt' => count(array_filter($beiId, fn ($n) => $n->status === 'entdeckt')),
            'quelle' => $quelle,
            'aktualisiert' => $aktualisiert?->toDateTimeString(),
        ];
    }

    /**
     * Wurzel der (nächsten) Baum-Komponente: Firewall vor allem anderen, sonst
     * der am stärksten vernetzte Knoten – bei uns der Masterswitch.
     */
    private function wurzelWaehlen(array $beiId, array $adjazenz, array $besucht): object
    {
        $beste = null;
        $besteWertung = null;

        foreach ($beiId as $node) {
            if (isset($besucht[$node->id])) {
                continue;
            }
            $wertung = [
                $node->art === 'firewall' ? 0 : 1,
                -count($adjazenz[$node->id] ?? []),
                $node->id,
            ];
            if ($beste === null || $wertung < $besteWertung) {
                $beste = $node;
                $besteWertung = $wertung;
            }
        }

        return $beste;
    }

    /** Rate menschenlesbar, z. B. "12,4 Mbit/s"; null bei 0 (dann zeigt die Karte nichts an). */
    public static function rateText(int $bps): ?string
    {
        if ($bps <= 0) {
            return null;
        }

        return match (true) {
            $bps >= 1_000_000_000 => number_format($bps / 1_000_000_000, 1, ',', '.').' Gbit/s',
            $bps >= 1_000_000 => number_format($bps / 1_000_000, 1, ',', '.').' Mbit/s',
            $bps >= 1_000 => number_format($bps / 1_000, 0, ',', '.').' kbit/s',
            default => $bps.' bit/s',
        };
    }

    private function leerZuNull(mixed $wert): ?string
    {
        $wert = trim((string) $wert);

        return $wert === '' ? null : $wert;
    }

    /** @return array{wurzeln: list<object>, quer: list<array<string, mixed>>, gesamt: int, online: int, entdeckt: int, quelle: string, aktualisiert: ?string} */
    private function leer(string $grund): array
    {
        return [
            'wurzeln' => [],
            'quer' => [],
            'gesamt' => 0,
            'online' => 0,
            'entdeckt' => 0,
            'quelle' => $grund,
            'aktualisiert' => null,
        ];
    }
}
