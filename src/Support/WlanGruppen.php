<?php

namespace Intranet\Modules\Netzwerk\Support;

use Illuminate\Support\Facades\DB;
use Intranet\Modules\Netzwerk\Netzwerk;
use Throwable;

/**
 * Überwachte WLAN-Gruppen des Alarm-Tasks (Einstellung „wlan_gruppen" von
 * Netzwerk/Alarme). Gespeichert wird ein Text in ekkon_task_settings:
 * „Gast-2G+Gast-5G=10; Lehrer=30" — je Gruppe die SSIDs (Plus = zusammen
 * zählen, etwa 2,4- und 5-GHz-Netz desselben WLANs) und die Schwelle.
 * Gepflegt wird das nicht von Hand, sondern über die Bedienung auf der
 * Task-Seite (Mehrfachauswahl aus den tatsächlich gesehenen SSIDs).
 */
class WlanGruppen
{
    public const TASK_KEY = 'Netzwerk/Alarme';

    public const SCHLUESSEL = 'wlan_gruppen';

    /** @return list<array{ssids: list<string>, schwelle: int}> */
    public static function parse(string $roh): array
    {
        $gruppen = [];
        foreach (explode(';', $roh) as $eintrag) {
            $eintrag = trim($eintrag);
            if ($eintrag === '') {
                continue;
            }
            $schwelle = 10;
            if (str_contains($eintrag, '=')) {
                [$eintrag, $zahl] = array_map('trim', explode('=', $eintrag, 2));
                if ($zahl !== '' && is_numeric($zahl)) {
                    $schwelle = max(0, (int) $zahl);
                }
            }
            $ssids = array_values(array_filter(array_map('trim', explode('+', $eintrag)), fn ($s) => $s !== ''));
            if ($ssids !== []) {
                $gruppen[] = ['ssids' => $ssids, 'schwelle' => $schwelle];
            }
        }

        return $gruppen;
    }

    /** @param list<array{ssids: list<string>, schwelle: int}> $gruppen */
    public static function serialisieren(array $gruppen): string
    {
        return implode('; ', array_map(
            fn (array $g) => implode('+', $g['ssids']).'='.$g['schwelle'],
            $gruppen,
        ));
    }

    /** Gespeicherte Gruppen lesen. @return list<array{ssids: list<string>, schwelle: int}> */
    public static function gespeichert(): array
    {
        $roh = DB::table('ekkon_task_settings')
            ->where('task_key', self::TASK_KEY)
            ->where('schluessel', self::SCHLUESSEL)
            ->value('wert');

        return self::parse((string) ($roh ?? ''));
    }

    /**
     * Gruppen speichern. Leere Liste = Zeile weg (Standard), wie es das
     * Ekkon-Formular auch macht. Query-Builder statt Eloquent: die Tabelle
     * hat einen zusammengesetzten Schlüssel und keine id-Spalte.
     *
     * @param list<array{ssids: list<string>, schwelle: int}> $gruppen
     */
    public static function speichern(array $gruppen): void
    {
        if ($gruppen === []) {
            DB::table('ekkon_task_settings')
                ->where('task_key', self::TASK_KEY)
                ->where('schluessel', self::SCHLUESSEL)
                ->delete();

            return;
        }
        DB::table('ekkon_task_settings')->updateOrInsert(
            ['task_key' => self::TASK_KEY, 'schluessel' => self::SCHLUESSEL],
            ['wert' => self::serialisieren($gruppen), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * Alle SSIDs, die der Collector je in network_wlan_clients gesehen hat
     * (Schnappschuss der eingebuchten Clients) — plus die bereits
     * konfigurierten, damit nichts aus der Auswahl verschwindet, nur weil
     * gerade niemand eingebucht ist.
     *
     * @return list<string>
     */
    public static function verfuegbareSsids(): array
    {
        $ssids = [];
        if ((bool) config('netzwerk.demo', false)) {
            foreach (DemoDaten::kartenRohdaten()['nodes'] as $n) {
                foreach (DemoDaten::knotenGeraete((int) $n->id) as $g) {
                    if (($g->verbunden_via ?? '') === 'wlan' && (string) $g->ssid !== '') {
                        $ssids[] = (string) $g->ssid;
                    }
                }
            }
        } elseif (Netzwerk::konfiguriert()) {
            try {
                $zeilen = DB::connection(Netzwerk::connection())->select(sprintf(
                    'SELECT DISTINCT ssid FROM %s.network_wlan_clients WHERE ssid IS NOT NULL',
                    Netzwerk::schema(),
                ));
                foreach ($zeilen as $z) {
                    $ssids[] = trim((string) $z->ssid);
                }
            } catch (Throwable) {
                // Tabelle fehlt noch (Collector-Update ausstehend) — dann nur die konfigurierten.
            }
        }
        foreach (self::gespeichert() as $g) {
            array_push($ssids, ...$g['ssids']);
        }
        $ssids = array_values(array_unique(array_filter($ssids, fn ($s) => $s !== '')));
        natcasesort($ssids);

        return array_values($ssids);
    }
}
