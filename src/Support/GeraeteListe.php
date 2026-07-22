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
        if (! Netzwerk::konfiguriert()) {
            return $this->leer('keine Datenquelle konfiguriert');
        }

        try {
            $rows = DB::connection(Netzwerk::connection())->select(
                sprintf(
                    'SELECT ip, mac, hostname, vendor, segment, lastSeen, firstSeen FROM %s.network_devices',
                    Netzwerk::schema(),
                )
            );
        } catch (Throwable $e) {
            report($e);

            return $this->leer('Fehler: '.$e->getMessage());
        }

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

        return [
            'segmente' => $segmente,
            'gesamt' => count($rows),
            'online' => count(array_filter($rows, fn ($r) => $r->online)),
            'quelle' => 'mssql',
            'aktualisiert' => $aktualisiert?->toDateTimeString(),
        ];
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
