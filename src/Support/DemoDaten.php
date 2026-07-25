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
            // Port-Namen im ifName-Format ("0/4"), wie sie LLDP auch liefert –
            // nur so findet die Detailseite den Uplink-Port in der Portleiste.
            self::link(1, '0/4', 2, '0/47'),
            self::link(2, '0/47', 1, '0/4'),
            self::link(1, '0/1', 3, '0/10'),
            self::link(3, '0/10', 1, '0/1'),
            self::link(1, '0/2', 4, '0/10'),
            self::link(1, '0/9', 5, '0/27'),
            self::link(1, '0/6', 6, 'LAN 1'),
            self::link(2, '0/12', 7, null),
            self::link(2, '0/48', 8, '0/24'),
            self::link(2, '0/7', 9, 'eth0'),
            // Fremd-Nachbarn ohne eigenen Node: PCs hinter unmanaged Verteiler.
            self::fremd(1, '0/13', '10:7c:61:0a:11:22', 'RYZEN-GRAFIK'),
            self::fremd(1, '0/13', '10:7c:61:0a:33:44', 'MUWALD5'),
            self::fremd(1, '0/13', null, 'MUWALD6'),
            // Redundanz-Kante: soll als Querverbindung erscheinen.
            self::link(3, '0/9', 4, '0/9'),
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

    /**
     * Beispiel-Geräteliste im Rückgabeformat von GeraeteListe::geraete() —
     * inklusive Phase-3-Verortung (anschluss), damit sich die Spalte ohne
     * echte MSSQL-Quelle entwickeln lässt.
     *
     * @return array{segmente: array<string, list<object>>, gesamt: int, online: int, quelle: string, aktualisiert: ?string}
     */
    public static function geraeteListe(): array
    {
        $frisch = now()->subMinutes(3);
        $stand = now()->subMinutes(4);

        $geraete = [
            self::geraet('192.168.0.21', '10:7c:61:0a:11:22', 'RYZEN-GRAFIK', 'Micro-Star', true, $frisch,
                self::anschluss('Masterswitch', '0/13', stand: $stand, nodeId: 1)),
            self::geraet('192.168.0.23', '10:7c:61:0a:33:44', 'MUWALD5', 'Micro-Star', true, $frisch,
                self::anschluss('Masterswitch', '0/13', stand: $stand, nodeId: 1)),
            self::geraet('192.168.0.52', 'b8:27:eb:12:34:56', 'netscan-pi', 'Raspberry Pi', true, $frisch,
                self::anschluss('Zentralswitch', '0/7', stand: $stand, nodeId: 2)),
            self::geraet('192.168.0.77', 'aa:5c:11:22:33:44', 'LAPTOP-LEHRER1', null, true, $frisch,
                self::anschluss('AP Turnhalle', null, via: 'wlan', ssid: 'Schule', stand: $stand, nodeId: 9)),
            self::geraet('192.168.0.90', 'de:ad:be:ef:00:90', null, 'Zebra Technologies', false, now()->subHours(6),
                self::anschluss('PoE Switch Werkstatt', '0/4', stand: now()->subHours(6), nodeId: 3)),
            self::geraet('192.168.0.113', null, 'drucker-verwaltung', null, true, $frisch, null),
            self::geraet('192.168.2.30', null, 'kasse-kueche', null, true, $frisch, null),
        ];

        $segmente = [];
        foreach ($geraete as $g) {
            $segmente[$g->segment][] = $g;
        }

        return [
            'segmente' => $segmente,
            'gesamt' => count($geraete),
            'online' => count(array_filter($geraete, fn ($g) => $g->online)),
            'quelle' => 'demo',
            'aktualisiert' => $frisch->toDateTimeString(),
        ];
    }

    private static function geraet(
        string $ip,
        ?string $mac,
        ?string $hostname,
        ?string $vendor,
        bool $online,
        \Illuminate\Support\Carbon $gesehen,
        ?object $anschluss,
    ): object {
        $segment = substr($ip, 0, (int) strrpos($ip, '.')).'.0/24';

        return (object) compact('ip', 'mac', 'hostname', 'vendor', 'segment', 'online', 'gesehen', 'anschluss');
    }

    private static function anschluss(
        string $node,
        ?string $port,
        string $via = 'lan',
        ?string $ssid = null,
        ?\Illuminate\Support\Carbon $stand = null,
        ?int $nodeId = null,
    ): object {
        $text = $via === 'wlan'
            ? $node.' · WLAN'.($ssid !== null ? ' ('.$ssid.')' : '')
            : $node.($port !== null ? ' · Port '.$port : '');

        return (object) ['text' => $text, 'via' => $via, 'node_id' => $nodeId, 'stand' => $stand];
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
                'name' => '0/'.$i,
                'operStatus' => $istAktiv ? 'up' : 'down',
                'adminStatus' => 'up',
                'speedMbit' => $istAktiv ? ($i <= 2 ? 10000 : 1000) : 0,
                'inBps' => $istAktiv ? intdiv($bpsGesamt, $aktiv * 2) : null,
                'outBps' => $istAktiv ? intdiv($bpsGesamt, $aktiv * 2) : null,
            ];
        }

        return $zeilen;
    }

    /**
     * Endgeräte für die Knoten-Detailseite (Demo): ein paar Geräte am
     * Masterswitch (1), eines am Werkstatt-Switch (3), WLAN-Clients am
     * AP Turnhalle (9).
     *
     * @return list<object>
     */
    public static function knotenGeraete(int $nodeId): array
    {
        $frisch = now()->subMinutes(3)->toDateTimeString();

        $geraet = fn (string $ip, ?string $mac, ?string $hostname, ?string $port, ?string $via = 'lan', ?string $ssid = null, ?string $gesehen = null) => (object) [
            'ip' => $ip,
            'mac' => $mac,
            'hostname' => $hostname,
            'vendor' => null,
            'port_name' => $port,
            'verbunden_via' => $via,
            'ssid' => $ssid,
            'lastSeen' => $gesehen ?? $frisch,
        ];

        return match ($nodeId) {
            1 => [
                $geraet('192.168.0.21', '10:7c:61:0a:11:22', 'RYZEN-GRAFIK', '0/13'),
                $geraet('192.168.0.23', '10:7c:61:0a:33:44', 'MUWALD5', '0/13'),
                $geraet('192.168.0.113', null, 'drucker-verwaltung', '0/5'),
                $geraet('192.168.0.66', '00:11:22:33:44:55', 'altgeraet', null, gesehen: now()->subDays(2)->toDateTimeString()),
            ],
            3 => [
                $geraet('192.168.0.90', 'de:ad:be:ef:00:90', null, '0/4'),
            ],
            9 => [
                $geraet('192.168.0.77', 'aa:5c:11:22:33:44', 'LAPTOP-LEHRER1', null, 'wlan', 'Schule'),
                $geraet('192.168.4.12', '2e:b3:0e:5f:97:79', null, null, 'wlan', 'Tablets'),
            ],
            default => [],
        };
    }
}
