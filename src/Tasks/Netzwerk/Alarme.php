<?php

namespace Intranet\Modules\Netzwerk\Tasks\Netzwerk;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Netzwerk\Models\KnotenStatus;
use Intranet\Modules\Netzwerk\Netzwerk;
use Intranet\Modules\Netzwerk\Support\DemoDaten;
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

    public string $description = 'Wacht über die Netzwerk-Infrastruktur: meldet Knoten, die nicht mehr antworten, Rückkehrer und neu entdeckte Geräte.';

    public array $meldungsarten = [
        'netzwerk-knoten-offline' => 'Netzwerk: Gerät antwortet nicht mehr',
        'netzwerk-knoten-wieder-online' => 'Netzwerk: Gerät wieder erreichbar',
        'netzwerk-knoten-entdeckt' => 'Netzwerk: neues Gerät entdeckt, noch nicht eingebunden',
    ];

    public array $einstellungen = [
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
        $gesehen = [];
        $offline = [];
        $wieder = [];
        $entdeckt = [];

        foreach ($zeilen as $z) {
            $matchkey = mb_strtolower(trim((string) ($z->matchKey ?? '')));
            if ($matchkey === '') {
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

        $zaehler = ['offline' => count($offline), 'wieder_online' => count($wieder), 'entdeckt' => count($entdeckt)];

        if ($baseline) {
            $this->msg('Erster Lauf: '.count($gesehen).' Knoten als Ausgangslage gemerkt — noch keine Meldungen.');
        } else {
            $this->msg(sprintf('%d Knoten geprüft: %d neu offline, %d wieder online, %d neu entdeckt.',
                count($gesehen), $zaehler['offline'], $zaehler['wieder_online'], $zaehler['entdeckt']));
        }
        foreach (array_unique($ohneZiel) as $art) {
            $this->msg('⚠ Für „'.($this->meldungsarten[$art] ?? $art).'" ist keine Benachrichtigungs-Route eingerichtet — die Meldung erreicht niemanden (Ekkon → Benachrichtigungen).');
        }

        return $zaehler + ['knoten' => count($gesehen), 'baseline' => $baseline, 'entfernt' => $entfernt];
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
