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
        $zaehler = ['offline' => 0, 'wieder_online' => 0, 'entdeckt' => 0];
        $ohneZiel = [];

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
                    $zaehler['entdeckt']++;
                    $this->melden($ohneZiel, 'netzwerk-knoten-entdeckt',
                        'Neues Netzwerk-Gerät entdeckt: '.$anzeige,
                        'Der Collector hat per LLDP ein neues Gerät gefunden'
                        .($ip !== null ? ' ('.$ip.')' : '')
                        .', kann es aber noch nicht abfragen. Zum Einbinden den SNMP-Benutzer „netmon" '
                        .'darauf anlegen (Details auf der Netzwerk-Karte im Intranet).',
                        ['name' => $anzeige, 'ip' => $ip, 'matchkey' => $matchkey],
                        'netzwerk-entdeckt:'.$matchkey);
                }
            } elseif ($status !== 'entdeckt') {
                if ($alt->online && ! $online) {
                    $zaehler['offline']++;
                    $this->melden($ohneZiel, 'netzwerk-knoten-offline',
                        'Netzwerk-Gerät antwortet nicht mehr: '.$anzeige,
                        $anzeige.($ip !== null ? ' ('.$ip.')' : '').' wurde zuletzt '
                        .($zuletzt !== null ? $zuletzt->locale('de')->isoFormat('LLL').' Uhr' : 'unbekannt')
                        .' gesehen (Schwelle: '.$schwelle.' Minuten).',
                        ['name' => $anzeige, 'ip' => $ip, 'zuletzt_gesehen' => (string) $zuletzt],
                        'netzwerk-offline:'.$matchkey.':'.($zuletzt?->getTimestamp() ?? 0));
                } elseif (! $alt->online && $online && ! $baseline && $entwarnung) {
                    $zaehler['wieder_online']++;
                    $this->melden($ohneZiel, 'netzwerk-knoten-wieder-online',
                        'Netzwerk-Gerät wieder erreichbar: '.$anzeige,
                        $anzeige.($ip !== null ? ' ('.$ip.')' : '').' meldet sich wieder.'
                        .($alt->zuletzt_gesehen !== null ? ' Zuvor zuletzt gesehen: '.$alt->zuletzt_gesehen->locale('de')->isoFormat('LLL').' Uhr.' : ''),
                        ['name' => $anzeige, 'ip' => $ip],
                        'netzwerk-wieder:'.$matchkey.':'.($alt->zuletzt_gesehen?->getTimestamp() ?? 0));
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

    /** benachrichtige() + merken, wenn die Meldungsart keine Route hat. */
    private function melden(array &$ohneZiel, string $art, string $titel, string $text, array $daten, string $idempotenz): void
    {
        $ergebnis = $this->benachrichtige($art, $titel, $text, $daten, $idempotenz);
        if ($ergebnis['ohne_ziel'] ?? false) {
            $ohneZiel[] = $art;
        }
        $this->msg($titel);
    }
}
