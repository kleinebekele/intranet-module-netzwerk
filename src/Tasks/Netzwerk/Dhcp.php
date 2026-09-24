<?php

namespace Intranet\Modules\Netzwerk\Tasks\Netzwerk;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Ekkon\Models\WebhookEingang;
use Intranet\Modules\Ekkon\Tasks\EkkonTask;
use Throwable;

/**
 * Übernimmt die Daten des DHCP-Servers aus dem Webhook-Eingang (Seite
 * Netzwerk → DHCP).
 *
 * Das Skript scripts/dhcp-statistik.ps1 läuft als geplante Aufgabe auf dem
 * Windows-DHCP-Server und schickt alle 15 Minuten ein JSON mit `dhcp_bereiche`:
 * je Bereich die Zählwerte, Grenzen, Ausschlüsse, Reservierungen und alle
 * Leases. Erkannt wird ein Eingang an diesem Feld, nicht an der Quelle – andere
 * Eingänge bleiben unberührt.
 *
 * Belegungen: Eine Zeile je ununterbrochener Belegung einer IP durch ein Gerät
 * (MAC). Taucht dieselbe Kombination innerhalb der Lücke wieder auf, wird
 * `zuletzt` fortgeschrieben, sonst beginnt eine neue Zeile. Gerätenamen und
 * MAC-Adressen sind personenbezogen (private Geräte) – deshalb die Aufbewahrung.
 *
 * Aufräumen: verarbeitete DHCP-Eingänge nach 7 Tagen (die Werte stehen dann in
 * den eigenen Tabellen), Zählwerte nach 400 Tagen, Belegungen nach Einstellung.
 */
class Dhcp extends EkkonTask
{
    public const MESSWERTE = 'netzwerk_dhcp_messwerte';

    public const BEREICHE = 'netzwerk_dhcp_bereiche';

    public const BELEGUNGEN = 'netzwerk_dhcp_belegungen';

    public const MANUELL = 'netzwerk_dhcp_manuell';

    public string $category = 'Netzwerk';

    public string $description = 'Auslastung und Belegungen des DHCP-Servers (Webhook-Eingang) für die Seite „DHCP" ablegen.';

    public array $einstellungen = [
        'luecke_minuten' => [
            'typ' => 'zahl',
            'label' => 'Lücke bis zu einer neuen Belegung (Minuten)',
            'standard' => 60,
            'hilfe' => 'Fehlt ein Gerät länger als diese Zeit in den Leases, zählt sein Wiederauftauchen als neue Belegung.',
        ],
        'aufbewahrung_tage' => [
            'typ' => 'zahl',
            'label' => 'Belegungen aufbewahren (Tage)',
            'standard' => 90,
            'hilfe' => 'Gerätenamen und MAC-Adressen sind personenbezogen. Ältere Belegungen werden gelöscht.',
        ],
    ];

    public function schedule(): string
    {
        return '*/5 * * * *';
    }

    public function run(): array
    {
        $offen = WebhookEingang::query()
            ->whereNull('verarbeitet_am')
            ->where('body', 'like', '%"dhcp_bereiche"%')
            ->orderBy('id')
            ->limit(50)
            ->get();

        $ergebnis = ['eingaenge' => $offen->count(), 'bereiche' => 0, 'belegungen_neu' => 0, 'belegungen_weiter' => 0, 'fehlerhaft' => 0];

        foreach ($offen as $eingang) {
            try {
                $stat = DB::transaction(fn () => $this->verarbeiten((string) $eingang->body, $eingang->created_at));
            } catch (Throwable $e) {
                $eingang->update(['verarbeitet_am' => now(), 'verarbeitung' => 'DHCP: unlesbar – '.mb_substr($e->getMessage(), 0, 200)]);
                $this->msg('Eingang #'.$eingang->id.' unlesbar: '.mb_substr($e->getMessage(), 0, 200));
                $ergebnis['fehlerhaft']++;

                continue;
            }

            $eingang->update(['verarbeitet_am' => now(), 'verarbeitung' => 'DHCP: '.$stat['bereiche'].' Bereich(e), '.$stat['leases'].' Leases']);
            $ergebnis['bereiche'] += $stat['bereiche'];
            $ergebnis['belegungen_neu'] += $stat['neu'];
            $ergebnis['belegungen_weiter'] += $stat['weiter'];
        }

        $ergebnis['eingaenge_geloescht'] = WebhookEingang::query()
            ->whereNotNull('verarbeitet_am')
            ->where('body', 'like', '%"dhcp_bereiche"%')
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        $ergebnis['messwerte_geloescht'] = DB::table(self::MESSWERTE)
            ->where('gemessen_am', '<', now()->subDays(400))
            ->delete();

        $tage = max(1, (int) $this->einstellung('aufbewahrung_tage'));
        $ergebnis['belegungen_geloescht'] = DB::table(self::BELEGUNGEN)
            ->where('zuletzt', '<', now()->subDays($tage))
            ->delete();

        return $ergebnis;
    }

    /** @return array{bereiche: int, leases: int, neu: int, weiter: int} */
    private function verarbeiten(string $body, ?Carbon $empfangen): array
    {
        $json = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $bereiche = self::liste($json['dhcp_bereiche'] ?? null);
        if ($bereiche === []) {
            throw new \RuntimeException('dhcp_bereiche fehlt oder ist leer');
        }

        $gemessen = isset($json['gemessen_am'])
            ? Carbon::parse((string) $json['gemessen_am'])->setTimezone(config('app.timezone'))
            : ($empfangen ?? now());
        // Ganze Sekunden: die Seite erkennt „aktuell belegt" am Gleichstand mit dem Bereich.
        $gemessen = $gemessen->copy()->startOfSecond();
        $server = isset($json['server']) ? self::text($json['server'], 255) : null;
        $luecke = max(16, (int) $this->einstellung('luecke_minuten'));

        $stat = ['bereiche' => 0, 'leases' => 0, 'neu' => 0, 'weiter' => 0];

        foreach ($bereiche as $b) {
            if (! is_array($b) || empty($b['scope'])) {
                continue;
            }
            $scope = self::text($b['scope'], 64);

            DB::table(self::MESSWERTE)->insert([
                'gemessen_am' => $gemessen,
                'scope' => $scope,
                'frei' => max(0, (int) ($b['frei'] ?? 0)),
                'belegt' => max(0, (int) ($b['belegt'] ?? 0)),
                'reserviert' => max(0, (int) ($b['reserviert'] ?? 0)),
                'auslastung' => round((float) ($b['auslastung'] ?? 0), 1),
                'created_at' => now(),
            ]);

            $reservierungen = array_values(array_filter(array_map(fn ($r) => is_array($r) && ! empty($r['ip']) ? [
                'ip' => self::text($r['ip'], 64),
                'mac' => self::mac($r['mac'] ?? ''),
                'name' => self::text($r['name'] ?? '', 255),
                'beschreibung' => self::text($r['beschreibung'] ?? '', 255),
            ] : null, self::liste($b['reservierungen'] ?? null))));

            $ausschluesse = array_values(array_filter(array_map(fn ($a) => is_array($a) && ! empty($a['von']) ? [
                'von' => self::text($a['von'], 64),
                'bis' => self::text($a['bis'] ?? $a['von'], 64),
            ] : null, self::liste($b['ausschluesse'] ?? null))));

            DB::table(self::BEREICHE)->updateOrInsert(['scope' => $scope], [
                'name' => self::text($b['name'] ?? '', 255) ?: null,
                'server' => $server,
                'maske' => self::text($b['maske'] ?? '', 64) ?: null,
                'von' => self::text($b['von'] ?? '', 64) ?: null,
                'bis' => self::text($b['bis'] ?? '', 64) ?: null,
                'lease_minuten' => isset($b['lease_minuten']) ? (int) $b['lease_minuten'] : null,
                'ausschluesse' => json_encode($ausschluesse),
                'reservierungen' => json_encode($reservierungen),
                'gemessen_am' => $gemessen,
                'updated_at' => now(),
                'created_at' => now(),
            ]);

            // Laufende Belegungen dieses Bereichs, die noch innerhalb der Lücke liegen.
            $laufend = DB::table(self::BELEGUNGEN)
                ->where('scope', $scope)
                ->where('zuletzt', '>=', $gemessen->copy()->subMinutes($luecke))
                ->orderBy('zuletzt')
                ->get(['id', 'ip', 'mac'])
                ->keyBy(fn ($z) => $z->ip.'|'.$z->mac);

            foreach (self::liste($b['leases'] ?? null) as $l) {
                if (! is_array($l) || empty($l['ip'])) {
                    continue;
                }
                $ip = self::text($l['ip'], 64);
                $mac = self::mac($l['mac'] ?? '');
                $werte = [
                    'geraet' => self::text($l['name'] ?? '', 255) ?: null,
                    'status' => self::text($l['status'] ?? '', 64) ?: null,
                    'reserviert' => str_contains(strtolower((string) ($l['status'] ?? '')), 'reservation'),
                    'ablauf' => ! empty($l['ablauf']) ? Carbon::parse((string) $l['ablauf'])->setTimezone(config('app.timezone')) : null,
                    'zuletzt' => $gemessen,
                ];

                $vorher = $laufend->get($ip.'|'.$mac);
                if ($vorher !== null) {
                    DB::table(self::BELEGUNGEN)->where('id', $vorher->id)->update($werte);
                    $stat['weiter']++;
                } else {
                    DB::table(self::BELEGUNGEN)->insert($werte + ['scope' => $scope, 'ip' => $ip, 'mac' => $mac, 'erstmals' => $gemessen]);
                    $stat['neu']++;
                }
                $stat['leases']++;
            }

            $stat['bereiche']++;
        }

        if ($stat['bereiche'] === 0) {
            throw new \RuntimeException('kein Bereich mit scope');
        }

        return $stat;
    }

    /** PowerShell macht aus einer Liste mit einem Eintrag ein Objekt – beides zulassen. */
    private static function liste(mixed $wert): array
    {
        if (! is_array($wert) || $wert === []) {
            return [];
        }

        return array_is_list($wert) ? $wert : [$wert];
    }

    private static function text(mixed $wert, int $max): string
    {
        return mb_substr(trim((string) $wert), 0, $max);
    }

    /** MAC einheitlich: aa-bb-cc-dd-ee-ff. */
    private static function mac(mixed $wert): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $wert) ?? '');

        return strlen($hex) === 12 ? implode('-', str_split($hex, 2)) : self::text($wert, 64);
    }
}
