<?php

namespace Intranet\Modules\Netzwerk\Tasks\Netzwerk;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Netzwerk\Models\AlarmZustand;
use Intranet\Modules\Netzwerk\Models\AusgeblendeterKnoten;
use Intranet\Modules\Netzwerk\Models\KnotenStatus;
use Intranet\Modules\Netzwerk\Netzwerk;
use Intranet\Modules\Netzwerk\Support\DemoDaten;
use Intranet\Modules\Netzwerk\Support\WlanGruppen;
use Intranet\Modules\Ekkon\Tasks\EkkonTask;
use Throwable;

/**
 * Alarme über die Ekkon-Benachrichtigungsrouten (Kür / Phase 6):
 *
 *  - Ein eingebundener Knoten (Switch/AP/Firewall/Controller) war online und
 *    wird nicht mehr gesehen  → „antwortet nicht mehr".
 *  - Er meldet sich zurück    → „wieder erreichbar" (abschaltbar).
 *  - Ein NEUER Knoten taucht mit Status „entdeckt" auf → Einbindungs-Hinweis.
 *
 * Übergänge entstehen aus dem Vergleich mit netzwerk_knoten_status (dem
 * Gedächtnis des letzten Laufs). Der ERSTE Lauf merkt sich nur die
 * Ausgangslage und meldet nichts — sonst gäbe es bei Einführung eine Flut.
 * Idempotenz je Ausfall: der Schlüssel enthält den letzten lastSeen-Stand,
 * derselbe Ausfall wird also nie doppelt gemeldet, ein NEUER Ausfall schon.
 *
 * Wohin die Meldungen gehen (Mail an Admins, Teams, …), bestimmt wie überall
 * Ekkon → Benachrichtigungen — ohne Route landet die Meldung als „ohne Ziel"
 * in der Historie, worauf der Task in seiner Lauf-Nachricht hinweist.
 */
class Alarme extends EkkonTask
{
    public string $category = 'Netzwerk';

    public string $description = 'Wacht über die Netzwerk-Infrastruktur: meldet Knoten, die nicht mehr antworten, Rückkehrer, neu entdeckte Geräte, Andrang in einem WLAN (z. B. Gäste) und WLAN-Geräte ohne Adresse (169.254).';

    public array $meldungsarten = [
        'netzwerk-knoten-offline' => 'Netzwerk: Gerät antwortet nicht mehr',
        'netzwerk-knoten-wieder-online' => 'Netzwerk: Gerät wieder erreichbar',
        'netzwerk-knoten-entdeckt' => 'Netzwerk: neues Gerät entdeckt, noch nicht eingebunden',
        'netzwerk-wlan-andrang' => 'Netzwerk: zu viele Geräte gleichzeitig in einem WLAN',
        'netzwerk-wlan-ohne-adresse' => 'Netzwerk: WLAN-Gerät eingebucht, aber ohne Adresse (169.254)',
    ];

    public array $einstellungen = [
        // Eigene Bedienung unter dem Formular (Mehrfachauswahl aus den gesehenen
        // SSIDs + Schwelle, Liste mit Entfernen); Wert: "A+B=10; C=30".
        'wlan_gruppen' => [
            'typ' => 'view',
            'view' => 'netzwerk::ekkon.wlan-gruppen',
            'label' => 'Überwachte WLANs',
            'standard' => '',
        ],
        'schwelle_minuten' => [
            'typ' => 'zahl',
            'label' => 'Offline ab (Minuten)',
            'standard' => 15,
            'hilfe' => 'So lange darf ein Knoten unsichtbar sein, bevor er als offline gilt. Der Collector läuft alle 5 Minuten — 15 heißt also: drei Läufe in Folge verpasst.',
        ],
        'entwarnung' => [
            'typ' => 'ja_nein',
            'label' => 'Entwarnung melden',
            'standard' => true,
            'hilfe' => 'Meldet auch, wenn ein zuvor ausgefallener Knoten wieder erreichbar ist.',
        ],
        'ohne_adresse_minuten' => [
            'typ' => 'zahl',
            'label' => 'WLAN ohne Adresse melden ab (Minuten)',
            'standard' => 10,
            'hilfe' => 'Ein Gerät ist im WLAN eingebucht, hat aber nur 169.254.x.x (keine Antwort vom DHCP-Server). Gemeldet wird, wenn das mindestens so lange anhält. 0 = nicht melden.',
        ],
    ];

    public function schedule(): string
    {
        return '*/10 * * * *';
    }

    public function run(): array
    {
        // ── Knoten holen (Demo-Daten für die lokale Entwicklung) ─────────────
        if ((bool) config('netzwerk.demo', false)) {
            $zeilen = DemoDaten::kartenRohdaten()['nodes'];
            foreach ($zeilen as $z) {
                $z->matchKey = 'demo:'.$z->id;
            }
        } elseif (! Netzwerk::konfiguriert()) {
            $this->msg('Keine Netzwerk-Datenquelle konfiguriert (NETZWERK_DB_* in der .env) — nichts zu überwachen.');

            return ['konfiguriert' => false];
        } else {
            try {
                $zeilen = DB::connection(Netzwerk::connection())->select(sprintf(
                    'SELECT matchKey, art, name, ip, status, lastSeen FROM %s.network_nodes',
                    Netzwerk::schema(),
                ));
            } catch (Throwable $e) {
                $this->msg('Netzwerk-Datenquelle nicht lesbar: '.$e->getMessage());

                return ['fehler' => true];
            }
        }

        $schwelle = max(5, (int) $this->einstellung('schwelle_minuten'));
        $grenze = now()->subMinutes($schwelle);
        $entwarnung = (bool) $this->einstellung('entwarnung');

        $bekannt = KnotenStatus::all()->keyBy('matchkey');
        $baseline = $bekannt->isEmpty();
        $versteckt = AusgeblendeterKnoten::schluesselMenge();   // per Knopf ausgeblendet: kein Alarm
        $gesehen = [];
        $offline = [];
        $wieder = [];
        $entdeckt = [];

        foreach ($zeilen as $z) {
            $matchkey = mb_strtolower(trim((string) ($z->matchKey ?? '')));
            if ($matchkey === '' || isset($versteckt[$matchkey])) {
                continue;
            }
            $gesehen[] = $matchkey;

            $name = trim((string) ($z->name ?? '')) ?: null;
            $ip = trim((string) ($z->ip ?? '')) ?: null;
            $status = trim((string) ($z->status ?? '')) ?: 'entdeckt';
            $anzeige = $name ?? $ip ?? $matchkey;
            $roh = trim((string) ($z->lastSeen ?? ''));
            $zuletzt = $roh === '' ? null : Carbon::parse($roh);
            $online = $zuletzt !== null && $zuletzt->greaterThanOrEqualTo($grenze);

            $alt = $bekannt->get($matchkey);

            if ($alt === null) {
                if (! $baseline && $status === 'entdeckt') {
                    $entdeckt[] = ['anzeige' => $anzeige, 'ip' => $ip, 'matchkey' => $matchkey];
                }
            } elseif ($status !== 'entdeckt') {
                if ($alt->online && ! $online) {
                    $offline[] = ['anzeige' => $anzeige, 'ip' => $ip, 'matchkey' => $matchkey, 'zuletzt' => $zuletzt];
                } elseif (! $alt->online && $online && ! $baseline && $entwarnung) {
                    $wieder[] = ['anzeige' => $anzeige, 'ip' => $ip, 'matchkey' => $matchkey, 'vorher' => $alt->zuletzt_gesehen];
                }
            }

            KnotenStatus::updateOrCreate(['matchkey' => $matchkey], [
                'name' => $anzeige,
                'status' => $status,
                'online' => $online,
                'zuletzt_gesehen' => $zuletzt,
            ]);
        }

        // Karteileichen (Collector hat den Node aufgeräumt) auch hier entsorgen.
        $entfernt = $gesehen === [] ? 0 : KnotenStatus::whereNotIn('matchkey', $gesehen)->delete();

        // ── Sammelmeldungen: je Lauf EINE Meldung pro Art statt einer je Gerät.
        //    Anzahl im Titel, Emojis je Zeile (kritisch rot, Entwarnung grün).
        $ohneZiel = [];
        if ($offline !== []) {
            $n = count($offline);
            $liste = array_map(fn ($g) => '🔴 '.$g['anzeige'].($g['ip'] !== null ? ' ('.$g['ip'].')' : '')
                .' — zuletzt gesehen: '.($g['zuletzt'] !== null ? $g['zuletzt']->locale('de')->isoFormat('LLL').' Uhr' : 'unbekannt'), $offline);
            $this->sammelmeldung($ohneZiel, 'netzwerk-knoten-offline',
                '🚨 '.$n.' Netzwerk-Gerät'.($n === 1 ? '' : 'e').' offline',
                'Folgende Geräte antworten nicht mehr (Schwelle: '.$schwelle." Minuten):\n\n".implode("\n", $liste),
                $offline, fn ($g) => $g['matchkey'].':'.($g['zuletzt']?->getTimestamp() ?? 0), 'netzwerk-offline-batch');
        }
        if ($wieder !== []) {
            $n = count($wieder);
            $liste = array_map(fn ($g) => '🟢 '.$g['anzeige'].($g['ip'] !== null ? ' ('.$g['ip'].')' : '')
                .($g['vorher'] !== null ? ' — zuvor zuletzt gesehen: '.$g['vorher']->locale('de')->isoFormat('LLL').' Uhr' : ''), $wieder);
            $this->sammelmeldung($ohneZiel, 'netzwerk-knoten-wieder-online',
                '✅ '.$n.' Netzwerk-Gerät'.($n === 1 ? '' : 'e').' wieder erreichbar',
                'Folgende Geräte melden sich wieder:'."\n\n".implode("\n", $liste),
                $wieder, fn ($g) => $g['matchkey'].':'.($g['vorher']?->getTimestamp() ?? 0), 'netzwerk-wieder-batch');
        }
        if ($entdeckt !== []) {
            $n = count($entdeckt);
            $liste = array_map(fn ($g) => '🔍 '.$g['anzeige'].($g['ip'] !== null ? ' ('.$g['ip'].')' : ''), $entdeckt);
            $this->sammelmeldung($ohneZiel, 'netzwerk-knoten-entdeckt',
                '🆕 '.$n.' neue'.($n === 1 ? 's' : '').' Netzwerk-Gerät'.($n === 1 ? '' : 'e').' entdeckt',
                'Der Collector hat per LLDP neue Geräte gefunden, kann sie aber noch nicht abfragen. '
                .'Zum Einbinden den SNMP-Benutzer „netmon" darauf anlegen (Details auf der Netzwerk-Karte):'."\n\n".implode("\n", $liste),
                $entdeckt, fn ($g) => $g['matchkey'], 'netzwerk-entdeckt-batch');
        }

        $andrang = $this->wlanAndrang($ohneZiel, $schwelle);
        $ohneAdresse = $this->wlanOhneAdresse($ohneZiel);

        $zaehler = ['offline' => count($offline), 'wieder_online' => count($wieder), 'entdeckt' => count($entdeckt), 'wlan_andrang' => $andrang, 'wlan_ohne_adresse' => $ohneAdresse];

        if ($baseline) {
            $this->msg('Erster Lauf: '.count($gesehen).' Knoten als Ausgangslage gemerkt — noch keine Meldungen.');
        } elseif ($offline !== [] || $wieder !== [] || $entdeckt !== []) {
            // Ruhige Läufe schweigen: ohne Nachricht blendet die Ekkon-Historie
            // den Lauf aus — sonst 144 gleichlautende Zeilen am Tag.
            $this->msg(sprintf('%d Knoten geprüft: %d neu offline, %d wieder online, %d neu entdeckt.',
                count($gesehen), $zaehler['offline'], $zaehler['wieder_online'], $zaehler['entdeckt']));
        }
        foreach (array_unique($ohneZiel) as $art) {
            $this->msg('⚠ Für „'.($this->meldungsarten[$art] ?? $art).'" ist keine Benachrichtigungs-Route eingerichtet — die Meldung erreicht niemanden (Ekkon → Benachrichtigungen).');
        }

        return $zaehler + ['knoten' => count($gesehen), 'baseline' => $baseline, 'entfernt' => $entfernt];
    }

    /**
     * WLAN-Andrang: sind in einem überwachten WLAN gerade MEHR Geräte
     * eingebucht als erlaubt? Quelle ist der Schnappschuss des Collectors
     * (network_wlan_clients, je Lauf ersetzt) — NICHT network_devices, denn
     * dort landen nur Geräte, die je per nmap/ARP erfasst wurden; Gäste-Handys
     * fehlen dort. Gemeldet wird der Übergang (Gedächtnis: netzwerk_alarm_
     * zustand); ein veralteter Schnappschuss (Collector steht) meldet nichts.
     *
     * Einstellung „wlan_gruppen": Gruppen durch Semikolon, SSIDs einer Gruppe
     * durch Plus (werden zusammengezählt — 2,4- und 5-GHz-Netz desselben
     * WLANs), „=Schwelle" je Gruppe; gepflegt über die Task-Seite.
     *
     * @return int Summe der eingebuchten Geräte aller überwachten Gruppen
     */
    private function wlanAndrang(array &$ohneZiel, int $schwelleMinuten): int
    {
        $gruppen = $this->wlanGruppen();
        if ($gruppen === []) {
            return 0;
        }

        // Anzahl je SSID (klein geschrieben) — eine Abfrage für alle Gruppen.
        if ((bool) config('netzwerk.demo', false)) {
            $jeSsid = [];
            foreach (DemoDaten::kartenRohdaten()['nodes'] as $n) {
                foreach (DemoDaten::knotenGeraete((int) $n->id) as $g) {
                    if (($g->verbunden_via ?? '') === 'wlan' && (string) $g->ssid !== '') {
                        $k = mb_strtolower((string) $g->ssid);
                        $jeSsid[$k] = ($jeSsid[$k] ?? 0) + 1;
                    }
                }
            }
            $alterMinuten = 0;
        } else {
            try {
                // Zählen auf dem Server, Alter als Zahl: Datetime-Werte roh
                // über ODBC zu lesen ist die bekannte Bufferfalle.
                $zeilen = DB::connection(Netzwerk::connection())->select(sprintf(
                    'SELECT LOWER(ssid) AS ssid, COUNT(*) AS anzahl FROM %s.network_wlan_clients WHERE ssid IS NOT NULL GROUP BY LOWER(ssid)',
                    Netzwerk::schema(),
                ));
                $alt = DB::connection(Netzwerk::connection())->selectOne(sprintf(
                    'SELECT DATEDIFF(minute, MAX(gesehen_am), SYSDATETIME()) AS alter_minuten FROM %s.network_wlan_clients',
                    Netzwerk::schema(),
                ));
            } catch (Throwable $e) {
                $this->msg('WLAN-Schnappschuss nicht lesbar (network_wlan_clients fehlt? Collector-Update + --init-db): '.$e->getMessage());

                return 0;
            }
            $jeSsid = [];
            foreach ($zeilen as $z) {
                $jeSsid[trim((string) $z->ssid)] = (int) $z->anzahl;
            }
            $roh = trim((string) ($alt->alter_minuten ?? ''));
            $alterMinuten = $roh === '' ? null : (int) $roh;
        }

        if ($alterMinuten === null || $alterMinuten > $schwelleMinuten) {
            $this->msg('WLAN-Andrang: Schnappschuss der eingebuchten Geräte ist '
                .($alterMinuten === null ? 'leer' : $alterMinuten.' Minuten alt').' — kein Urteil möglich (läuft der Collector?).');

            return 0;
        }

        $summe = 0;
        foreach ($gruppen as $gruppe) {
            $anzahl = 0;
            $teile = [];
            foreach ($gruppe['ssids'] as $ssid) {
                $n = $jeSsid[mb_strtolower($ssid)] ?? 0;
                $anzahl += $n;
                $teile[] = $ssid.': '.$n;
            }
            $summe += $anzahl;
            $grenze = $gruppe['schwelle'];
            $anzeige = implode(' + ', $gruppe['ssids']);
            $detail = count($teile) > 1 ? ' ('.implode(', ', $teile).')' : '';

            $schluessel = 'wlan-andrang:'.mb_strtolower(implode('+', $gruppe['ssids']));
            $zustand = AlarmZustand::lesen($schluessel);
            $warUeber = (bool) ($zustand['ueber'] ?? false);
            $istUeber = $anzahl > $grenze;

            if ($istUeber && ! $warUeber) {
                $seit = now();
                $ergebnis = $this->benachrichtige('netzwerk-wlan-andrang',
                    '📶 Andrang im WLAN „'.$anzeige.'": '.$anzahl.' Geräte gleichzeitig',
                    'Im WLAN „'.$anzeige.'" sind gerade '.$anzahl.' Geräte eingebucht'.$detail.' (Schwelle: mehr als '.$grenze.').'
                    ."\n\nStand: ".$seit->locale('de')->isoFormat('LLL').' Uhr. Die nächste Meldung kommt erst, wenn die Zahl zwischendurch wieder unter die Schwelle gefallen ist.',
                    ['ssids' => $gruppe['ssids'], 'anzahl' => $anzahl, 'schwelle' => $grenze],
                    'netzwerk-wlan-andrang:'.$schluessel.':'.$seit->getTimestamp());
                if ($ergebnis['ohne_ziel'] ?? false) {
                    $ohneZiel[] = 'netzwerk-wlan-andrang';
                }
                $this->msg('Andrang im WLAN „'.$anzeige.'": '.$anzahl.' Geräte'.$detail.' (Schwelle '.$grenze.') — gemeldet.');
                AlarmZustand::schreiben($schluessel, ['ueber' => true, 'seit' => $seit->toDateTimeString(), 'anzahl' => $anzahl]);
            } elseif (! $istUeber && $warUeber) {
                $this->msg('WLAN „'.$anzeige.'": Andrang vorbei, '.$anzahl.' Geräte'.$detail.' (Schwelle '.$grenze.').');
                AlarmZustand::schreiben($schluessel, ['ueber' => false, 'anzahl' => $anzahl]);
            }
        }

        return $summe;
    }

    /**
     * WLAN ohne Adresse: Geräte, die am AP eingebucht sind, aber seit
     * mindestens N Minuten nur 169.254.x.x haben (kein DHCP). Quelle ist der
     * Verlauf des Collectors (network_wlan_ohne_ip, eine Zeile je Zeitraum,
     * zuletzt je Lauf fortgeschrieben). Jeder Zeitraum wird genau einmal
     * gemeldet (Gedächtnis: netzwerk_alarm_zustand, Schlüssel = Zeilen-IDs).
     *
     * @return int Anzahl der gerade betroffenen Geräte
     */
    private function wlanOhneAdresse(array &$ohneZiel): int
    {
        $minuten = (int) $this->einstellung('ohne_adresse_minuten');
        if ($minuten <= 0 || (bool) config('netzwerk.demo', false)) {
            return 0;
        }

        $schema = Netzwerk::schema();
        try {
            // Offen = im letzten Lauf noch gesehen (Collector alle 5 Min.).
            $offen = DB::connection(Netzwerk::connection())->select(
                "SELECT o.id, o.mac, o.ip, o.ssid, o.ap_name, d.hostname,
                        DATEDIFF(minute, o.erstmals, o.zuletzt) AS minuten
                 FROM {$schema}.network_wlan_ohne_ip o
                 LEFT JOIN (SELECT LOWER(mac) AS mac, MAX(NULLIF(hostname, '')) AS hostname
                            FROM {$schema}.network_devices WHERE NULLIF(mac, '') IS NOT NULL GROUP BY LOWER(mac)) d
                        ON d.mac = o.mac
                 WHERE o.zuletzt >= DATEADD(minute, -10, SYSDATETIME())"
            );
        } catch (Throwable $e) {
            $this->msg('WLAN ohne Adresse: Verlauf nicht lesbar (Collector-Update mit schema_phase6.sql + --init-db?): '.$e->getMessage());

            return 0;
        }

        $schluessel = 'wlan-ohne-adresse';
        $gemeldet = array_map('intval', (array) (AlarmZustand::lesen($schluessel)['gemeldet'] ?? []));
        $offeneIds = array_map(fn ($z) => (int) $z->id, $offen);

        $neu = array_values(array_filter($offen,
            fn ($z) => (int) $z->minuten >= $minuten && ! in_array((int) $z->id, $gemeldet, true)));

        if ($neu !== []) {
            $geraete = array_map(function ($z) {
                $name = trim((string) $z->hostname) ?: trim((string) $z->mac);

                return [
                    'anzeige' => $name,
                    'id' => (int) $z->id,
                    'zeile' => '🟠 '.$name.' ('.trim((string) $z->mac).')'
                        .' — WLAN „'.(trim((string) $z->ssid) ?: '?').'" an '.(trim((string) $z->ap_name) ?: '?')
                        .', '.trim((string) $z->ip).' seit '.(int) $z->minuten.' Minuten',
                ];
            }, $neu);
            $n = count($geraete);
            $this->sammelmeldung($ohneZiel, 'netzwerk-wlan-ohne-adresse',
                '📵 '.$n.' WLAN-Gerät'.($n === 1 ? '' : 'e').' ohne Adresse',
                'Folgende Geräte sind im WLAN eingebucht, bekommen aber keine Adresse vom DHCP-Server (169.254.x.x, Schwelle: '.$minuten." Minuten):\n\n"
                .implode("\n", array_column($geraete, 'zeile'))
                ."\n\nVerlauf und AP-Wechsel: Netzwerk → WLAN.",
                $geraete, fn ($g) => (string) $g['id'], 'netzwerk-ohne-adresse-batch');
            $gemeldet = array_merge($gemeldet, array_column($geraete, 'id'));
        }

        // Nur offene Zeiträume merken – abgeschlossene kommen nie wieder.
        AlarmZustand::schreiben($schluessel, ['gemeldet' => array_values(array_intersect($gemeldet, $offeneIds))]);

        return count($offen);
    }

    /**
     * Die überwachten WLAN-Gruppen („Gast-2G+Gast-5G=10; Lehrer=30"), gepflegt
     * über die Bedienung auf der Task-Seite (WlanGruppen).
     *
     * @return list<array{ssids: list<string>, schwelle: int}>
     */
    private function wlanGruppen(): array
    {
        return WlanGruppen::parse((string) $this->einstellung('wlan_gruppen'));
    }

    /**
     * Eine Sammelmeldung je Art abschicken — alle betroffenen Geräte eines
     * Laufs in EINER Benachrichtigung statt einer je Gerät. Der Idempotenz-
     * Schlüssel entsteht aus dem Inhalt der Sammlung ($signatur je Gerät):
     * derselbe Satz Geräte wird nie doppelt gemeldet, ein anderer (neuer
     * Ausfall oder Rückkehrer) sehr wohl.
     */
    private function sammelmeldung(array &$ohneZiel, string $art, string $titel, string $text, array $geraete, callable $signatur, string $praefix): void
    {
        $idempotenz = $praefix.':'.md5(implode('|', array_map($signatur, $geraete)));
        $daten = ['anzahl' => count($geraete), 'geraete' => array_map(fn ($g) => $g['anzeige'], $geraete)];
        $ergebnis = $this->benachrichtige($art, $titel, $text, $daten, $idempotenz);
        if ($ergebnis['ohne_ziel'] ?? false) {
            $ohneZiel[] = $art;
        }
        $this->msg($titel);
    }
}
