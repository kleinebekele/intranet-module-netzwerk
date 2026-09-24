<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Support\GeraeteListe;
use Intranet\Modules\Netzwerk\Support\GeraeteMeta;
use Intranet\Modules\Netzwerk\Tasks\Netzwerk\Dhcp;

/**
 * Seite „DHCP": je Bereich die Adresskarte, der Verlauf der freien Adressen
 * und wer wann welche Adresse hatte. Die DHCP-Daten kommen vom DHCP-Server
 * (Task Netzwerk/Dhcp), dazu nimmt die Karte das Ping-Inventar
 * (network_devices) und die Pflege-Daten der Geräte (Typ, Standort, Info).
 *
 * Jede Adresse des Netzes hat zwei Merkmale:
 *  - Pool: DHCP möglich (zwischen von und bis, nicht ausgeschlossen) oder nicht
 *    (ausgeschlossen oder außerhalb des Bereichs)
 *  - Belegung: Reservierung (DHCP-Server), Lease, antwortet (Gerät mit fester
 *    IP im Ping-Scan), manuell belegt (im Intranet gepflegt – Geräte, die nicht
 *    antworten) oder frei
 */
class DhcpController extends Controller
{
    /** Zeitraum-Schlüssel => [Beschriftung, Stunden] */
    private const ZEITRAEUME = [
        '24h' => ['24 Stunden', 24],
        '7t' => ['7 Tage', 24 * 7],
        '30t' => ['30 Tage', 24 * 30],
        '1j' => ['1 Jahr', 24 * 365],
    ];

    /** Größere Netze bekommen keine Adresskarte (Kästchen je Adresse). */
    private const KARTE_MAX = 1024;

    public function index(Request $request, GeraeteListe $liste): View
    {
        $zeitraum = array_key_exists((string) $request->query('zeitraum'), self::ZEITRAEUME)
            ? (string) $request->query('zeitraum')
            : '7t';
        $suche = trim((string) $request->query('suche', ''));

        $leer = ['bereiche' => [], 'zeitraum' => $zeitraum, 'zeitraeume' => self::ZEITRAEUME, 'suche' => $suche, 'belegungen' => null];
        if (! Schema::hasTable(Dhcp::BEREICHE)) {
            return view('netzwerk::dhcp', $leer);
        }

        $ab = now()->subHours(self::ZEITRAEUME[$zeitraum][1]);

        // Ping-Inventar je IP (mit Pflege-Daten) und Pflege-Nachschlagen für den Rest.
        $inventar = [];
        foreach ($liste->geraete()['segmente'] as $geraete) {
            foreach ($geraete as $g) {
                $inventar[$g->ip] = $g;
            }
        }
        $nachschlagen = GeraeteMeta::nachschlagen();

        $bereiche = [];
        foreach (DB::table(Dhcp::BEREICHE)->orderBy('scope')->get() as $bereich) {
            $bereiche[] = $this->bereich($bereich, $ab, $inventar, $nachschlagen);
        }

        // Wer hatte wann welche Adresse – im gewählten Zeitraum, optional gefiltert.
        $belegungen = DB::table(Dhcp::BELEGUNGEN)
            ->where('zuletzt', '>=', $ab)
            ->when($suche !== '', function ($q) use ($suche): void {
                $muster = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $suche).'%';
                $q->where(fn ($w) => $w->where('ip', 'like', $muster)
                    ->orWhere('mac', 'like', $muster)
                    ->orWhere('geraet', 'like', $muster));
            })
            ->orderByDesc('zuletzt')
            ->orderBy('ip')
            ->paginate(100)
            ->withQueryString();

        return view('netzwerk::dhcp', [
            'bereiche' => $bereiche,
            'zeitraum' => $zeitraum,
            'zeitraeume' => self::ZEITRAEUME,
            'suche' => $suche,
            'belegungen' => $belegungen,
        ]);
    }

    /** Adresse von Hand als belegt markieren (oder Bezeichnung ändern). */
    public function manuellSpeichern(Request $request): RedirectResponse
    {
        $daten = $request->validate([
            'scope' => ['required', 'string', 'max:64'],
            'ip' => ['required', 'ip'],
            'bezeichnung' => ['required', 'string', 'max:255'],
            'notiz' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->pruefeImNetz($daten['scope'], $daten['ip']);

        $vorher = DB::table(Dhcp::MANUELL)->where('scope', $daten['scope'])->where('ip', $daten['ip'])->first();

        DB::table(Dhcp::MANUELL)->updateOrInsert(
            ['scope' => $daten['scope'], 'ip' => $daten['ip']],
            [
                'bezeichnung' => $daten['bezeichnung'],
                'notiz' => $daten['notiz'] ?: null,
                'geaendert_von' => $request->user()?->id,
                'updated_at' => now(),
                'created_at' => $vorher->created_at ?? now(),
            ],
        );

        $this->audit('dhcp.manuell', $daten['ip'].' manuell belegt: '.$daten['bezeichnung'], array_filter([
            'vorher' => $vorher?->bezeichnung,
            'nachher' => $daten['bezeichnung'],
            'notiz' => $daten['notiz'] ?? null,
        ]), $daten['ip']);

        return $this->zurueck($request, $daten['ip'].' ist als manuell belegt gespeichert.');
    }

    /** Manuelle Belegung aufheben – die Adresse gilt wieder als frei. */
    public function manuellEntfernen(Request $request): RedirectResponse
    {
        $daten = $request->validate([
            'scope' => ['required', 'string', 'max:64'],
            'ip' => ['required', 'ip'],
        ]);

        $vorher = DB::table(Dhcp::MANUELL)->where('scope', $daten['scope'])->where('ip', $daten['ip'])->first();
        if ($vorher !== null) {
            DB::table(Dhcp::MANUELL)->where('id', $vorher->id)->delete();
            $this->audit('dhcp.manuell_frei', $daten['ip'].' manuelle Belegung aufgehoben ('.$vorher->bezeichnung.')', [], $daten['ip']);
        }

        return $this->zurueck($request, $daten['ip'].' ist nicht mehr manuell belegt.');
    }

    /** Audit-Log des Cores, sofern vorhanden (ältere Plattform-Stände haben keins). */
    private function audit(string $aktion, string $text, array $daten, string $ziel): void
    {
        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben($aktion, $text, daten: $daten, ziel: $ziel);
        }
    }

    private function zurueck(Request $request, string $meldung): RedirectResponse
    {
        $ziel = route('module.netzwerk.dhcp', array_filter(['zeitraum' => $request->input('zeitraum')]));

        return redirect()->to($ziel)->with('status', $meldung);
    }

    private function pruefeImNetz(string $scope, string $ip): void
    {
        $bereich = DB::table(Dhcp::BEREICHE)->where('scope', $scope)->first();
        $netz = ip2long($scope);
        $maske = ip2long((string) ($bereich->maske ?? '255.255.255.0'));
        $zahl = ip2long($ip);

        abort_if($bereich === null || $netz === false || $maske === false || $zahl === false
            || ($zahl & $maske) !== ($netz & $maske), 422, 'Die Adresse gehört nicht zu diesem Bereich.');
    }

    /** @return array<string, mixed> */
    private function bereich(object $bereich, Carbon $ab, array $inventar, \Closure $nachschlagen): array
    {
        $gemessen = $bereich->gemessen_am ? Carbon::parse($bereich->gemessen_am) : null;
        $ausschluesse = json_decode((string) $bereich->ausschluesse, true) ?: [];
        $reservierungen = collect(json_decode((string) $bereich->reservierungen, true) ?: [])->keyBy('ip');

        // Aktuell = in der jüngsten Messung gesehen.
        $aktuell = $gemessen === null ? collect() : DB::table(Dhcp::BELEGUNGEN)
            ->where('scope', $bereich->scope)
            ->where('zuletzt', $gemessen)
            ->get()
            ->keyBy('ip');

        $manuell = Schema::hasTable(Dhcp::MANUELL)
            ? DB::table(Dhcp::MANUELL)->where('scope', $bereich->scope)->get()->keyBy('ip')
            : collect();

        $verlauf = DB::table(Dhcp::MESSWERTE)
            ->where('scope', $bereich->scope)
            ->where('gemessen_am', '>=', $ab)
            ->orderBy('gemessen_am')
            ->get(['gemessen_am', 'frei', 'belegt']);

        $karte = $this->karte($bereich, $ausschluesse, $reservierungen->all(), $aktuell->all(), $manuell->all(), $inventar, $nachschlagen);

        // Zahlen aus der Karte – so passen Kacheln und Kästchen immer zusammen.
        $zaehle = fn (callable $f) => $karte === null ? null : count(array_filter($karte, $f));

        return [
            'b' => $bereich,
            'gemessen' => $gemessen,
            'zahlen' => [
                'pool' => $zaehle(fn ($k) => $k['pool']),
                'pool_frei' => $zaehle(fn ($k) => $k['pool'] && $k['belegung'] === 'frei'),
                'lease' => $zaehle(fn ($k) => $k['belegung'] === 'lease'),
                'reservierung' => $zaehle(fn ($k) => $k['belegung'] === 'reservierung'),
                'ping' => $zaehle(fn ($k) => $k['belegung'] === 'ping'),
                'manuell' => $zaehle(fn ($k) => $k['belegung'] === 'manuell'),
                'statisch_frei' => $zaehle(fn ($k) => ! $k['pool'] && $k['belegung'] === 'frei'),
            ],
            'engpass' => $verlauf->sortBy('frei')->first(),
            'karte' => $karte,
            'punkte' => $this->verdichten($verlauf->map(fn ($p) => [Carbon::parse($p->gemessen_am)->getTimestamp(), (int) $p->frei, (int) $p->belegt])->all()),
        ];
    }

    /**
     * Ein Eintrag je Hostadresse des Netzes.
     *
     * Belegung, wenn mehreres zutrifft: Reservierung vor Lease vor manuell vor
     * antwortet. Eine manuelle Markierung auf einer Adresse, die der DHCP-Server
     * selbst vergeben hat, bleibt sichtbar (Feld `manuell`) – das ist ein Konflikt.
     * Antwortet ein manuell belegtes Gerät, ist das kein Konflikt, sondern die
     * Bestätigung – die Belegung bleibt „manuell".
     *
     * @return list<array<string, mixed>>|null  null, wenn das Netz zu groß ist
     */
    private function karte(object $bereich, array $ausschluesse, array $reservierungen, array $aktuell, array $manuell, array $inventar, \Closure $nachschlagen): ?array
    {
        $netz = ip2long((string) $bereich->scope);
        $maske = ip2long((string) ($bereich->maske ?: '255.255.255.0'));
        $von = ip2long((string) $bereich->von);
        $bis = ip2long((string) $bereich->bis);
        if ($netz === false || $maske === false) {
            return null;
        }

        $anzahl = (~$maske & 0xFFFFFFFF) + 1;
        if ($anzahl > self::KARTE_MAX + 2 || $anzahl < 4) {
            return null;
        }

        $sperren = array_values(array_filter(
            array_map(fn ($a) => [ip2long((string) $a['von']), ip2long((string) $a['bis'])], $ausschluesse),
            fn ($p) => $p[0] !== false && $p[1] !== false,
        ));

        $karte = [];
        for ($i = 1; $i < $anzahl - 1; $i++) {
            $zahl = ($netz & $maske) + $i;
            $ip = long2ip($zahl);
            $lease = $aktuell[$ip] ?? null;
            $res = $reservierungen[$ip] ?? null;
            $man = $manuell[$ip] ?? null;
            $inv = $inventar[$ip] ?? null;

            $imBereich = $von !== false && $bis !== false && $zahl >= $von && $zahl <= $bis;
            $ausgeschlossen = false;
            foreach ($sperren as [$sv, $sb]) {
                if ($zahl >= $sv && $zahl <= $sb) {
                    $ausgeschlossen = true;
                    break;
                }
            }

            $belegung = match (true) {
                $res !== null => 'reservierung',
                $lease !== null => 'lease',
                $man !== null => 'manuell',
                $inv !== null && $inv->online => 'ping',
                default => 'frei',
            };

            // MAC für die Pflege-Daten: aus Lease/Reservierung, sonst aus dem Inventar.
            $mac = $lease->mac ?? ($res['mac'] ?? null);
            $macPflege = $mac !== null ? str_replace('-', ':', mb_strtolower($mac)) : $inv?->mac;
            $pflege = $inv?->pflege ?? GeraeteMeta::anzeige($nachschlagen($macPflege, $ip), null, []);

            $karte[] = [
                'ip' => $ip,
                'letztes' => $i,
                'pool' => $imBereich && ! $ausgeschlossen,
                'grund' => $imBereich ? ($ausgeschlossen ? 'ausgeschlossen' : 'pool') : 'ausserhalb',
                'belegung' => $belegung,
                'aktiv' => $lease !== null,
                'geraet' => $lease->geraet ?? ($res['name'] ?? $inv?->hostname),
                'mac' => $mac ?? $inv?->mac,
                'hersteller' => $inv?->vendor,
                'beschreibung' => $res['beschreibung'] ?? null,
                'seit' => isset($lease->erstmals) ? Carbon::parse($lease->erstmals)->format('d.m.Y H:i') : null,
                'ablauf' => isset($lease->ablauf) ? Carbon::parse($lease->ablauf)->format('d.m.Y H:i') : null,
                'ping' => $inv === null ? null : ($inv->online ? 'antwortet' : 'zuletzt gesehen '.($inv->gesehen?->format('d.m.Y H:i') ?? '–')),
                'typ' => $pflege->typ,
                'standort' => $pflege->standort,
                'info' => $pflege->info,
                'bearbeiten' => route('module.netzwerk.geraet', array_filter([
                    'mac' => $macPflege,
                    'ip' => $ip,
                    'anzeige' => $inv?->hostname ?? ($lease->geraet ?? ($res['name'] ?? $ip)),
                    'zurueck' => route('module.netzwerk.dhcp', absolute: false),
                ])),
                'manuell' => $man ? ['bezeichnung' => $man->bezeichnung, 'notiz' => $man->notiz, 'am' => Carbon::parse($man->updated_at)->format('d.m.Y H:i')] : null,
            ];
        }

        return $karte;
    }

    /**
     * Höchstens ~600 Punkte je Linie. Je Abschnitt zählt der Engpass (wenigste
     * freie Adressen), damit ein kurzer Ansturm im Jahresbild nicht verschwindet.
     *
     * @param  list<array{int,int,int}>  $punkte
     * @return list<array{int,int,int}>
     */
    private function verdichten(array $punkte, int $max = 600): array
    {
        $anzahl = count($punkte);
        if ($anzahl <= $max) {
            return $punkte;
        }

        $groesse = (int) ceil($anzahl / $max);
        $ergebnis = [];
        foreach (array_chunk($punkte, $groesse) as $abschnitt) {
            usort($abschnitt, fn ($a, $b) => $a[1] <=> $b[1]);
            $ergebnis[] = $abschnitt[0];
        }
        usort($ergebnis, fn ($a, $b) => $a[0] <=> $b[0]);

        return $ergebnis;
    }
}
