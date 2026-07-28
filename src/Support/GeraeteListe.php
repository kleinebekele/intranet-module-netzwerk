<?php

namespace Intranet\Modules\Netzwerk\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Netzwerk\Netzwerk;
use Throwable;

/**
 * Liest das Geräte-Inventar aus {schema}.network_devices – geschrieben vom
 * externen Collector (Raspberry Pi), gelesen hier read-only.
 *
 * "online" wird beim LESEN aus lastSeen berechnet, NICHT der gespeicherte
 * isOnline-Wert verwendet: Fällt der Collector selbst aus, kommt kein Scan
 * mehr – dann soll die Übersicht ehrlich "offline" zeigen statt für immer den
 * letzten Stand einzufrieren.
 */
class GeraeteListe
{
    /**
     * @return array{segmente: array<string, list<object>>, gesamt: int, online: int, quelle: string, aktualisiert: ?string}
     */
    public function geraete(): array
    {
        if ((bool) config('netzwerk.demo', false)) {
            $daten = DemoDaten::geraeteListe();
            $this->anreichern($daten['segmente']);

            return $daten;
        }

        if (! Netzwerk::konfiguriert()) {
            return $this->leer('keine Datenquelle konfiguriert');
        }

        try {
            $rows = DB::connection(Netzwerk::connection())->select(
                sprintf(
                    'SELECT id, ip, mac, hostname, vendor, segment, lastSeen, firstSeen FROM %s.network_devices',
                    Netzwerk::schema(),
                )
            );
        } catch (Throwable $e) {
            report($e);

            return $this->leer('Fehler: '.$e->getMessage());
        }

        $anschluesse = $this->anschluesse();

        $grenze = now()->subMinutes((int) config('netzwerk.offline_ab_minuten', 15));
        $aktualisiert = null;

        foreach ($rows as $r) {
            // ODBC-Grenze: SQL-NULL kommt als "" und Werte teils mit Rand-
            // Leerzeichen zurück – einmal hier sauber machen, statt später
            // überall zu raten.
            $r->hostname = $this->leerZuNull($r->hostname);
            $r->vendor = $this->leerZuNull($r->vendor);
            $r->mac = $this->leerZuNull($r->mac);
            $r->ip = trim((string) $r->ip);

            $r->gesehen = ($r->lastSeen === null || trim((string) $r->lastSeen) === '')
                ? null
                : Carbon::parse($r->lastSeen);
            $r->online = $r->gesehen !== null && $r->gesehen->greaterThanOrEqualTo($grenze);
            $r->anschluss = $anschluesse[(int) $r->id] ?? null;

            if ($r->gesehen !== null && ($aktualisiert === null || $r->gesehen->greaterThan($aktualisiert))) {
                $aktualisiert = $r->gesehen;
            }
        }

        // Innerhalb des Segments numerisch nach IP sortieren (varchar-Sortierung
        // würde .10 vor .2 einsortieren).
        usort($rows, fn ($a, $b) => [$a->segment, ip2long($a->ip) ?: 0] <=> [$b->segment, ip2long($b->ip) ?: 0]);

        $segmente = [];
        foreach ($rows as $r) {
            $segmente[$r->segment][] = $r;
        }

        $this->anreichern($segmente);

        return [
            'segmente' => $segmente,
            'gesamt' => count($rows),
            'online' => count(array_filter($rows, fn ($r) => $r->online)),
            'quelle' => 'mssql',
            'aktualisiert' => $aktualisiert?->toDateTimeString(),
        ];
    }

    /**
     * Verortung aus Phase 3 (FDB/WLAN): device-id => Anschluss-Objekt.
     *
     * Eigene Abfrage mit eigenem try/catch — läuft das Modul-Update vor dem
     * Collector-Update (Spalten fehlen noch), bleibt die Liste trotzdem
     * benutzbar, nur ohne Anschluss-Spalte.
     *
     * @return array<int, object{text: string, via: string, stand: ?Carbon}>
     */
    private function anschluesse(): array
    {
        try {
            $rows = DB::connection(Netzwerk::connection())->select(
                sprintf(
                    'SELECT d.id, d.port_name, d.verbunden_via, d.ssid, d.zugeordnet_am,
                            n.id AS node_id, n.name AS node_name, n.ip AS node_ip
                     FROM %1$s.network_devices d
                     JOIN %1$s.network_nodes n ON n.id = d.node_id',
                    Netzwerk::schema(),
                )
            );
        } catch (Throwable) {
            return [];
        }

        $anschluesse = [];
        foreach ($rows as $r) {
            $node = $this->leerZuNull($r->node_name) ?? $this->leerZuNull($r->node_ip) ?? 'unbenannt';
            $port = $this->leerZuNull($r->port_name);
            $ssid = $this->leerZuNull($r->ssid);
            $via = $this->leerZuNull($r->verbunden_via) ?? 'lan';

            $text = $via === 'wlan'
                ? $node.' · WLAN'.($ssid !== null ? ' ('.$ssid.')' : '')
                : $node.($port !== null ? ' · Port '.$port : '');

            $stand = $this->leerZuNull((string) $r->zugeordnet_am);
            $anschluesse[(int) $r->id] = (object) [
                'text' => $text,
                'via' => $via,
                'node_id' => (int) $r->node_id,
                'stand' => $stand !== null ? Carbon::parse($stand) : null,
            ];
        }

        return $anschluesse;
    }

    /**
     * Ein einzelnes Gerät aus dem Inventar, für die Detail-/Bearbeiten-Seite:
     * Hostname, Hersteller, MAC, Anschluss, online/zuletzt gesehen. Gesucht
     * wird über die MAC (bevorzugt), ersatzweise die IP; null, wenn das
     * Inventar nichts kennt (dann zeigt die Seite nur die Kennung).
     */
    public function finden(?string $mac, ?string $ip): ?object
    {
        if ((bool) config('netzwerk.demo', false)) {
            foreach (DemoDaten::geraeteListe()['segmente'] as $geraete) {
                foreach ($geraete as $g) {
                    if (($mac !== null && $g->mac === $mac) || ($mac === null && $ip !== null && $g->ip === $ip)) {
                        return $g;
                    }
                }
            }

            return null;
        }

        if (! Netzwerk::konfiguriert()) {
            return null;
        }

        try {
            $sql = sprintf(
                'SELECT id, ip, mac, hostname, vendor, segment, lastSeen FROM %s.network_devices WHERE %s',
                Netzwerk::schema(),
                $mac !== null ? 'LOWER(mac) = ?' : 'ip = ?',
            );
            $r = DB::connection(Netzwerk::connection())->select($sql, [$mac ?? $ip])[0] ?? null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        if ($r === null) {
            return null;
        }

        $r->hostname = $this->leerZuNull($r->hostname);
        $r->vendor = $this->leerZuNull($r->vendor);
        $r->mac = $this->leerZuNull($r->mac);
        $r->ip = trim((string) $r->ip);
        $gesehenRoh = trim((string) ($r->lastSeen ?? ''));
        $r->gesehen = $gesehenRoh === '' ? null : Carbon::parse($gesehenRoh);
        $r->online = $r->gesehen !== null
            && $r->gesehen->greaterThanOrEqualTo(now()->subMinutes((int) config('netzwerk.offline_ab_minuten', 15)));
        $r->anschluss = $this->anschluesse()[(int) $r->id] ?? null;

        return $r;
    }

    /**
     * Pflege-Daten (Typ/Standort/Info) an jede Zeile hängen — gepflegter Typ
     * gewinnt, sonst der automatisch erkannte (kursiv in der Anzeige).
     *
     * @param  array<string, list<object>>  $segmente
     */
    private function anreichern(array $segmente): void
    {
        $nachschlagen = GeraeteMeta::nachschlagen();
        $typNamen = GeraeteMeta::typNamen();

        foreach ($segmente as $geraete) {
            foreach ($geraete as $g) {
                $g->pflege = GeraeteMeta::anzeige(
                    $nachschlagen($g->mac, $g->ip),
                    TypErkennung::fuerGeraet($g->hostname, $g->vendor),
                    $typNamen,
                );
            }
        }
    }

    private function leerZuNull(mixed $wert): ?string
    {
        $wert = trim((string) $wert);

        return $wert === '' ? null : $wert;
    }

    /** @return array{segmente: array<string, list<object>>, gesamt: int, online: int, quelle: string, aktualisiert: ?string} */
    private function leer(string $grund): array
    {
        return ['segmente' => [], 'gesamt' => 0, 'online' => 0, 'quelle' => $grund, 'aktualisiert' => null];
    }
}
