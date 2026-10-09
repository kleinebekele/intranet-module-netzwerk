<?php

namespace Intranet\Modules\Netzwerk\Support;

use Illuminate\Support\Facades\DB;
use Intranet\Modules\Netzwerk\Netzwerk;
use Throwable;

/**
 * Daten der WLAN-Seite (Phase 6 des Collectors):
 *
 *  - „Ohne Adresse": eingebuchte Clients mit 169.254.x.x, also ohne Antwort
 *    vom DHCP-Server – jetzt (Schnappschuss network_wlan_clients) und als
 *    Verlauf (network_wlan_ohne_ip, eine Zeile je Zeitraum).
 *  - Wechsel-Rangliste: wer taucht zwischen zwei Collector-Läufen an einem
 *    anderen AP auf (network_wlan_wechsel)? Der Collector sieht nur alle
 *    5 Minuten hin; die Zahlen sind Untergrenzen.
 *
 * Datumswerte kommen als Text aus MSSQL (CONVERT … 120): Datetime-Spalten roh
 * über ODBC zu lesen ist die bekannte Bufferfalle.
 */
class WlanDaten
{
    /** Wählbare Zeiträume: Beschriftung + Stunden zurück. */
    public const ZEITRAEUME = [
        '24h' => ['24 Stunden', 24],
        '7t' => ['7 Tage', 24 * 7],
        '30t' => ['30 Tage', 24 * 30],
    ];

    /** So viele Zeilen zeigen Verlauf und Rangliste höchstens. */
    private const MAX_ZEILEN = 200;

    /**
     * @return array{zeitraum: string, aktuell: list<object>, verlauf: list<object>,
     *               rangliste: list<object>, hinweis: ?string, quelle: string}
     */
    public function auswertung(string $zeitraum): array
    {
        $zeitraum = array_key_exists($zeitraum, self::ZEITRAEUME) ? $zeitraum : '7t';
        $stunden = self::ZEITRAEUME[$zeitraum][1];
        $leer = ['zeitraum' => $zeitraum, 'aktuell' => [], 'verlauf' => [], 'rangliste' => [], 'hinweis' => null, 'quelle' => 'mssql'];

        if ((bool) config('netzwerk.demo', false)) {
            return ['zeitraum' => $zeitraum, 'hinweis' => null, 'quelle' => 'demo'] + self::demo();
        }
        if (! Netzwerk::konfiguriert()) {
            return ['hinweis' => 'Keine Netzwerk-Datenquelle konfiguriert (NETZWERK_DB_* in der .env).'] + $leer;
        }

        $schema = Netzwerk::schema();
        $db = DB::connection(Netzwerk::connection());
        // Hostnamen aus dem Inventar (MACs dort teils groß geschrieben).
        $namen = "(SELECT LOWER(mac) AS mac, MAX(NULLIF(hostname, '')) AS hostname
                   FROM {$schema}.network_devices WHERE NULLIF(mac, '') IS NOT NULL GROUP BY LOWER(mac))";

        try {
            $aktuell = $db->select(
                "SELECT w.mac, w.ip, w.ssid, w.ap_name, d.hostname
                 FROM {$schema}.network_wlan_clients w
                 LEFT JOIN {$namen} d ON d.mac = w.mac
                 WHERE w.ip LIKE '169.254.%'
                 ORDER BY w.ap_name, w.mac"
            );

            $verlauf = $db->select(sprintf(
                "SELECT TOP %d o.mac, o.ip, o.ssid, o.ap_name, d.hostname,
                        CONVERT(varchar(19), o.erstmals, 120) AS erstmals,
                        CONVERT(varchar(19), o.zuletzt, 120) AS zuletzt,
                        DATEDIFF(minute, o.erstmals, o.zuletzt) AS minuten
                 FROM {$schema}.network_wlan_ohne_ip o
                 LEFT JOIN {$namen} d ON d.mac = o.mac
                 WHERE o.zuletzt >= DATEADD(hour, -%d, SYSDATETIME())
                 ORDER BY o.erstmals DESC",
                self::MAX_ZEILEN, $stunden,
            ));

            $rangliste = $db->select(sprintf(
                "SELECT TOP %d w.mac, COUNT(*) AS anzahl, MAX(w.ssid) AS ssid, MAX(d.hostname) AS hostname,
                        CONVERT(varchar(19), MAX(w.am), 120) AS zuletzt
                 FROM {$schema}.network_wlan_wechsel w
                 LEFT JOIN {$namen} d ON d.mac = w.mac
                 WHERE w.am >= DATEADD(hour, -%d, SYSDATETIME())
                 GROUP BY w.mac
                 ORDER BY COUNT(*) DESC, w.mac",
                self::MAX_ZEILEN, $stunden,
            ));

            // AP-Paare je Client (A→B und B→A zählen als dasselbe Paar).
            $paare = $db->select(sprintf(
                "SELECT mac, von_ap_name, von_ap_ip, nach_ap_name, nach_ap_ip, COUNT(*) AS anzahl
                 FROM {$schema}.network_wlan_wechsel
                 WHERE am >= DATEADD(hour, -%d, SYSDATETIME())
                 GROUP BY mac, von_ap_name, von_ap_ip, nach_ap_name, nach_ap_ip",
                $stunden,
            ));
        } catch (Throwable $e) {
            return ['hinweis' => 'WLAN-Auswertung nicht lesbar – ist der Collector auf dem Stand mit Phase 6 '
                .'(schema_phase6.sql, danach --init-db)? '.$e->getMessage()] + $leer;
        }

        return [
            'zeitraum' => $zeitraum,
            'aktuell' => array_map(fn ($z) => $this->zeile($z), $aktuell),
            'verlauf' => array_map(fn ($z) => $this->zeile($z), $verlauf),
            'rangliste' => $this->mitPaaren(array_map(fn ($z) => $this->zeile($z), $rangliste), $paare),
            'hinweis' => null,
            'quelle' => 'mssql',
        ];
    }

    /** Leerstrings (ODBC liefert NULL als '') zu null, Zahlen zu int. */
    private function zeile(object $z): object
    {
        foreach (get_object_vars($z) as $feld => $wert) {
            $wert = is_string($wert) ? trim($wert) : $wert;
            $z->{$feld} = $wert === '' ? null : $wert;
        }
        foreach (['anzahl', 'minuten'] as $feld) {
            if (isset($z->{$feld})) {
                $z->{$feld} = (int) $z->{$feld};
            }
        }
        $z->anzeige = $z->hostname ?? $z->mac;

        return $z;
    }

    /**
     * Hängt jedem Client der Rangliste seine AP-Paare an, häufigstes zuerst:
     * $z->paare = [['a' => 'AP-X', 'b' => 'AP-Y', 'anzahl' => 7], …].
     *
     * @param  list<object>  $rangliste
     * @param  list<object>  $paare
     * @return list<object>
     */
    private function mitPaaren(array $rangliste, array $paare): array
    {
        $jeMac = [];
        foreach ($paare as $p) {
            $von = trim((string) ($p->von_ap_name ?: $p->von_ap_ip)) ?: '?';
            $nach = trim((string) ($p->nach_ap_name ?: $p->nach_ap_ip)) ?: '?';
            [$a, $b] = strcmp($von, $nach) <= 0 ? [$von, $nach] : [$nach, $von];
            $mac = trim((string) $p->mac);
            $jeMac[$mac][$a.'|'.$b] = ($jeMac[$mac][$a.'|'.$b] ?? 0) + (int) $p->anzahl;
        }

        foreach ($rangliste as $z) {
            $liste = $jeMac[$z->mac] ?? [];
            arsort($liste);
            $z->paare = [];
            foreach ($liste as $schluessel => $anzahl) {
                [$a, $b] = explode('|', $schluessel, 2);
                $z->paare[] = ['a' => $a, 'b' => $b, 'anzahl' => $anzahl];
            }
        }

        return $rangliste;
    }

    /** Erfundene Beispieldaten für die lokale Entwicklung (NETZWERK_DEMO=true). */
    private static function demo(): array
    {
        $vor = fn (int $minuten) => now()->subMinutes($minuten)->format('Y-m-d H:i:s');
        $o = fn (array $w) => (object) $w;

        $laptop = ['mac' => '02:11:22:33:44:55', 'hostname' => 'laptop-07.example.local', 'anzeige' => 'laptop-07.example.local'];
        $handy = ['mac' => '06:aa:bb:cc:dd:ee', 'hostname' => null, 'anzeige' => '06:aa:bb:cc:dd:ee'];

        return [
            'aktuell' => [
                $o($laptop + ['ip' => '169.254.12.34', 'ssid' => 'Buero-2G', 'ap_name' => 'AP-Buero']),
            ],
            'verlauf' => [
                $o($laptop + ['ip' => '169.254.12.34', 'ssid' => 'Buero-2G', 'ap_name' => 'AP-Buero', 'erstmals' => $vor(25), 'zuletzt' => $vor(0), 'minuten' => 25]),
                $o($handy + ['ip' => '169.254.56.78', 'ssid' => 'Gast-5G', 'ap_name' => 'AP-Raum-12', 'erstmals' => $vor(300), 'zuletzt' => $vor(295), 'minuten' => 5]),
            ],
            'rangliste' => [
                $o($laptop + ['anzahl' => 23, 'ssid' => 'Buero-2G', 'zuletzt' => $vor(5), 'paare' => [
                    ['a' => 'AP-OG-Flur', 'b' => 'AP-Buero', 'anzahl' => 22],
                    ['a' => 'AP-EG-Flur', 'b' => 'AP-Buero', 'anzahl' => 1],
                ]]),
                $o($handy + ['anzahl' => 4, 'ssid' => 'Gast-5G', 'zuletzt' => $vor(290), 'paare' => [
                    ['a' => 'AP-Raum-12', 'b' => 'AP-OG-Flur', 'anzahl' => 2],
                    ['a' => 'AP-OG-Flur', 'b' => 'AP-Buero', 'anzahl' => 2],
                ]]),
            ],
        ];
    }
}
