<?php

namespace Intranet\Modules\Netzwerk\Support;

/**
 * Beispieldaten für die lokale Entwicklung (NETZWERK_DEMO=true in der .env).
 *
 * Die Entwicklungsumgebung erreicht die echte MSSQL-Quelle nicht – mit diesen
 * Daten lässt sich die Karte trotzdem ansehen und stylen. Die Topologie ist dem
 * echten Schulnetz nachempfunden (ein Master, daran Etagen-Switches, ein
 * WLAN-Controller, ein entdeckter, noch nicht eingebundener Switch), die Werte
 * sind frei erfunden.
 */
class DemoDaten
{
    /** @return array{nodes: list<object>, links: list<object>, ports: list<object>} */
    public static function kartenRohdaten(): array
    {
        $frisch = now()->subMinutes(2)->toDateTimeString();
        $alt = now()->subHours(3)->toDateTimeString();

        $nodes = [
            self::node(1, 'switch', 'Masterswitch', '192.168.0.180', 'M4300-8X8F ProSAFE', 'aktiv', $frisch),
            self::node(2, 'switch', 'Zentralswitch', '192.168.0.176', 'M4300-52G ProSAFE', 'aktiv', $frisch),
            self::node(3, 'switch', 'PoE Switch Werkstatt', '192.168.0.185', 'M4200-10MG-PoE+', 'aktiv', $frisch),
            self::node(4, 'switch', 'Switch Eurythmie', '192.168.0.182', 'M4200-10MG-PoE+', 'aktiv', $frisch),
            self::node(5, 'switch', 'SlaveSwitch', '192.168.0.184', 'S3300-28X ProSAFE', 'aktiv', $frisch),
            self::node(6, 'controller', 'WLAN-Controller', '192.168.0.181', 'WC7500 ProSafe', 'aktiv', $frisch, standort: '0.06 Hausanschlussraum'),
            self::node(7, 'switch', 'Switch OG2 (unbekannt)', '192.168.0.190', null, 'entdeckt', $frisch),
            self::node(8, 'switch', 'Kellerswitch', '192.168.0.188', 'GS724T', 'stumm', $alt),
            self::node(9, 'ap', 'AP Turnhalle', '192.168.0.45', 'WAC730', 'aktiv', $frisch),
        ];

        $links = [
            // Jede Verbindung bewusst doppelt (beide LLDP-Sichten) – so kommt
            // sie auch aus der echten Tabelle, die Deduplizierung soll arbeiten.
            self::link(1, 'Port 4', 2, 'Port 417'),
            self::link(2, 'Port 417', 1, 'Port 4'),
            self::link(1, 'Port 1', 3, 'Port 11'),
            self::link(3, 'Port 11', 1, 'Port 1'),
            self::link(1, 'Port 2', 4, 'Port 11'),
            self::link(1, 'Port 9', 5, 'Port 313'),
            self::link(1, 'Port 6', 6, 'LAN 1'),
            self::link(2, 'Port 12', 7, null),
            self::link(2, 'Port 48', 8, 'Port 24'),
            self::link(2, 'Port 7', 9, 'eth0'),
            // Fremd-Nachbarn ohne eigenen Node: PCs hinter unmanaged Verteiler.
            self::fremd(1, 'Port 13', '10:7c:61:0a:11:22', 'RYZEN-GRAFIK'),
            self::fremd(1, 'Port 13', '10:7c:61:0a:33:44', 'MUWALD5'),
            self::fremd(1, 'Port 13', null, 'MUWALD6'),
            // Redundanz-Kante: soll als Querverbindung erscheinen.
            self::link(3, 'Port 12', 4, 'Port 12'),
        ];

        $ports = array_merge(
            self::ports(1, 16, 9, 480_000_000),
            self::ports(2, 52, 31, 1_900_000_000),
            self::ports(3, 10, 4, 60_000_000),
            self::ports(4, 10, 6, 22_000_000),
            self::ports(5, 28, 12, 140_000_000),
            self::ports(6, 2, 1, 95_000_000),
        );

        return ['nodes' => $nodes, 'links' => $links, 'ports' => $ports];
    }

    private static function node(
        int $id,
        string $art,
        string $name,
        string $ip,
        ?string $modell,
        string $status,
        string $lastSeen,
        ?string $standort = null,
    ): object {
        return (object) [
            'id' => $id,
            'art' => $art,
            'name' => $name,
            'ip' => $ip,
            'modell' => $modell,
            'firmware' => null,
            'standort' => $standort,
            'status' => $status,
            'lastSeen' => $lastSeen,
        ];
    }

    private static function link(int $von, ?string $vonPort, int $zu, ?string $zuPort): object
    {
        return (object) [
            'von_node_id' => $von,
            'von_port' => $vonPort,
            'zu_node_id' => $zu,
            'zu_port' => $zuPort,
            'zu_fremd_mac' => null,
            'zu_fremd_name' => null,
        ];
    }

    private static function fremd(int $von, string $vonPort, ?string $mac, string $name): object
    {
        return (object) [
            'von_node_id' => $von,
            'von_port' => $vonPort,
            'zu_node_id' => null,
            'zu_port' => null,
            'zu_fremd_mac' => $mac,
            'zu_fremd_name' => $name,
        ];
    }

    /** @return list<object> Simple Port-Zeilen: $aktiv Stück "up", Rest "down"; die Rate verteilt auf die aktiven. */
    private static function ports(int $nodeId, int $gesamt, int $aktiv, int $bpsGesamt): array
    {
        $zeilen = [];
        for ($i = 1; $i <= $gesamt; $i++) {
            $istAktiv = $i <= $aktiv;
            $zeilen[] = (object) [
                'node_id' => $nodeId,
                'operStatus' => $istAktiv ? 'up' : 'down',
                'inBps' => $istAktiv ? intdiv($bpsGesamt, $aktiv * 2) : null,
                'outBps' => $istAktiv ? intdiv($bpsGesamt, $aktiv * 2) : null,
            ];
        }

        return $zeilen;
    }
}
