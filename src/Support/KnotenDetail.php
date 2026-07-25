<?php

namespace Intranet\Modules\Netzwerk\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Netzwerk\Netzwerk;
use Throwable;

/**
 * Daten der Knoten-Detailseite: ein Node aus network_nodes samt Portleiste
 * (network_ports), Nachbarn (network_links, beide Richtungen) und den dort
 * angeschlossenen Endgeräten (network_devices.node_id, Phase 3).
 *
 * "online" wie überall beim LESEN aus lastSeen berechnet.
 */
class KnotenDetail
{
    /**
     * @return ?array{knoten: object, ports: list<object>, nachbarn: list<array<string, mixed>>,
     *                wlan: list<object>, weitere: list<object>, quelle: string}
     */
    public function detail(int $id): ?array
    {
        if ((bool) config('netzwerk.demo', false)) {
            ['nodes' => $nodes, 'links' => $links, 'ports' => $ports] = DemoDaten::kartenRohdaten();
            $geraete = DemoDaten::knotenGeraete($id);
            $quelle = 'demo';
        } elseif (! Netzwerk::konfiguriert()) {
            return null;
        } else {
            try {
                $schema = Netzwerk::schema();
                $db = DB::connection(Netzwerk::connection());

                $nodes = $db->select("SELECT id, art, name, ip, modell, firmware, standort, status, lastSeen FROM {$schema}.network_nodes");
                $links = $db->select("SELECT von_node_id, von_port, zu_node_id, zu_port, zu_fremd_mac, zu_fremd_name FROM {$schema}.network_links WHERE von_node_id = ? OR zu_node_id = ?", [$id, $id]);
                $ports = $db->select("SELECT node_id, name, operStatus, adminStatus, speedMbit, inBps, outBps FROM {$schema}.network_ports WHERE node_id = ?", [$id]);
                $geraete = $db->select("SELECT ip, mac, hostname, vendor, port_name, verbunden_via, ssid, lastSeen FROM {$schema}.network_devices WHERE node_id = ?", [$id]);
            } catch (Throwable $e) {
                report($e);

                return null;
            }

            $quelle = 'mssql';
        }

        $grenze = now()->subMinutes((int) config('netzwerk.offline_ab_minuten', 15));

        // ── Der Knoten selbst (+ Namensverzeichnis für die Nachbarn) ──────────
        $knoten = null;
        $namen = [];
        foreach ($nodes as $n) {
            $n->id = (int) $n->id;
            foreach (['art', 'name', 'ip', 'modell', 'firmware', 'standort', 'status'] as $feld) {
                $n->$feld = $this->leerZuNull($n->$feld ?? null);
            }
            $namen[$n->id] = $n;
            if ($n->id === $id) {
                $knoten = $n;
            }
        }
        if ($knoten === null) {
            return null;
        }

        $gesehenRoh = trim((string) ($knoten->lastSeen ?? ''));
        $knoten->gesehen = $gesehenRoh === '' ? null : Carbon::parse($gesehenRoh);
        $knoten->online = $knoten->gesehen !== null && $knoten->gesehen->greaterThanOrEqualTo($grenze);
        $knoten->art ??= 'switch';
        $knoten->status ??= 'entdeckt';

        // ── Nachbarn: Kanten in beide Richtungen, dedupliziert je Partner ─────
        $nachbarn = [];
        $uplinkJePort = [];
        foreach ($links as $l) {
            $von = (int) $l->von_node_id;
            $zuRoh = trim((string) ($l->zu_node_id ?? ''));

            if ($von === $id && $zuRoh === '') {
                // LLDP-Fremdgerät direkt an diesem Knoten (kein eigener Node).
                $name = $this->leerZuNull($l->zu_fremd_name) ?? $this->leerZuNull($l->zu_fremd_mac) ?? 'unbekannt';
                $nachbarn['f|'.$name] = [
                    'name' => $name,
                    'partner' => null,
                    'port' => $this->leerZuNull($l->von_port),
                    'fremd' => true,
                ];

                continue;
            }

            $zu = $zuRoh === '' ? null : (int) $zuRoh;
            if ($zu === null || ($von !== $id && $zu !== $id) || $von === $zu) {
                continue;
            }

            $partnerId = $von === $id ? $zu : $von;
            $eigenerPort = $this->leerZuNull($von === $id ? $l->von_port : $l->zu_port);
            $partnerPort = $this->leerZuNull($von === $id ? $l->zu_port : $l->von_port);
            $partner = $namen[$partnerId] ?? null;
            if ($partner === null) {
                continue;
            }

            $nachbarn['n|'.$partnerId] ??= [
                'name' => $partner->name ?? $partner->ip ?? 'unbenannt',
                'partner' => $partner,
                'port' => $eigenerPort,
                'partnerPort' => $partnerPort,
                'fremd' => false,
            ];
            if ($eigenerPort !== null) {
                $uplinkJePort[$eigenerPort] = $partner->name ?? $partner->ip ?? 'unbenannt';
            }
        }

        // ── Endgeräte normalisieren, nach Port gruppieren ─────────────────────
        $lanJePort = [];
        $wlan = [];
        $weitere = [];
        foreach ($geraete as $g) {
            foreach (['mac', 'hostname', 'vendor', 'port_name', 'verbunden_via', 'ssid'] as $feld) {
                $g->$feld = $this->leerZuNull($g->$feld ?? null);
            }
            $g->ip = trim((string) $g->ip);
            $gesehenRoh = trim((string) ($g->lastSeen ?? ''));
            $g->gesehen = $gesehenRoh === '' ? null : Carbon::parse($gesehenRoh);
            $g->online = $g->gesehen !== null && $g->gesehen->greaterThanOrEqualTo($grenze);
            $g->anzeige = $g->hostname ?? $g->ip;

            if ($g->verbunden_via === 'wlan') {
                $wlan[] = $g;
            } elseif ($g->port_name !== null) {
                $lanJePort[$g->port_name][] = $g;
            } else {
                $weitere[] = $g;
            }
        }

        // ── Portleiste: natürliche Sortierung (0/2 vor 0/10) ──────────────────
        $portListe = [];
        foreach ($ports as $p) {
            if ((int) $p->node_id !== $id) {
                continue;   // Demo-Daten liefern die Ports ALLER Knoten
            }
            $p->name = $this->leerZuNull($p->name) ?? '?';
            $p->operStatus = trim((string) $p->operStatus);
            $p->adminStatus = trim((string) $p->adminStatus);
            $p->speedMbit = (int) $p->speedMbit;
            $p->rate = KartenDaten::rateText((int) $p->inBps + (int) $p->outBps);
            $p->uplink = $uplinkJePort[$p->name] ?? null;
            $p->geraete = $lanJePort[$p->name] ?? [];
            unset($lanJePort[$p->name]);
            $portListe[] = $p;
        }
        usort($portListe, fn ($a, $b) => strnatcasecmp($a->name, $b->name));

        // Geräte an Ports, die der Collector (gerade) nicht listet.
        foreach ($lanJePort as $rest) {
            foreach ($rest as $g) {
                $weitere[] = $g;
            }
        }

        usort($wlan, fn ($a, $b) => strnatcasecmp($a->anzeige ?? '', $b->anzeige ?? ''));

        return [
            'knoten' => $knoten,
            'ports' => $portListe,
            'nachbarn' => array_values($nachbarn),
            'wlan' => $wlan,
            'weitere' => $weitere,
            'quelle' => $quelle,
        ];
    }

    private function leerZuNull(mixed $wert): ?string
    {
        $wert = trim((string) $wert);

        return $wert === '' ? null : $wert;
    }
}
