<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Models\Geraet;
use Intranet\Modules\Netzwerk\Models\Geraetetyp;
use Intranet\Modules\Netzwerk\Models\Standort;

/**
 * Pflege-Formular je Gerät: Typ, Standort, Info.
 *
 * Das Gerät kommt aus der (fremden, read-only) Inventar-DB und wird hier nur
 * über MAC bzw. IP aus der Adresszeile identifiziert — beides wird streng
 * validiert, damit kein Müll als Schlüssel in der Pflege-Tabelle landet.
 */
class GeraetController extends Controller
{
    private const MAC_MUSTER = '/^[0-9a-fA-F]{2}([:-][0-9a-fA-F]{2}){5}$/';

    public function bearbeiten(Request $request): View
    {
        [$mac, $ip] = $this->kennung($request);
        $geraet = $this->finden($mac, $ip);

        return view('netzwerk::geraet-bearbeiten', [
            'mac' => $mac,
            'ip' => $ip,
            'anzeige' => trim((string) $request->query('anzeige')) ?: ($ip ?? $mac),
            'zurueck' => $this->zurueck($request),
            'geraet' => $geraet,
            'erkannt' => trim((string) $request->query('erkannt')) ?: null,
            'typen' => Geraetetyp::orderBy('name')->get(),
            'standorte' => Standort::orderBy('gebaeude')->orderBy('stockwerk')->orderBy('raum')->get(),
        ]);
    }

    public function speichern(Request $request): RedirectResponse
    {
        [$mac, $ip] = $this->kennung($request);

        $daten = $request->validate(
            [
                'geraetetyp_id' => ['nullable', 'integer', 'exists:netzwerk_geraetetypen,id'],
                'standort_id' => ['nullable', 'integer', 'exists:netzwerk_standorte,id'],
                'info' => ['nullable', 'string', 'max:2000'],
            ],
            [], ['geraetetyp_id' => 'Gerätetyp', 'standort_id' => 'Standort', 'info' => 'Info'],
        );
        $daten['info'] = trim((string) ($daten['info'] ?? '')) ?: null;

        $geraet = $this->finden($mac, $ip);

        if ($daten['geraetetyp_id'] === null && $daten['standort_id'] === null && $daten['info'] === null) {
            // Alles geleert -> Zeile weg, statt leere Hüllen zu sammeln.
            $geraet?->delete();
        } else {
            $geraet ??= new Geraet;
            // MAC nachziehen (Zeile stammt ggf. aus IP-Zeiten), IP aktuell halten.
            $geraet->mac ??= $mac;
            $geraet->ip = $ip ?? $geraet->ip;
            $geraet->fill($daten)->save();
        }

        return redirect($this->zurueck($request))
            ->with('status', 'Gerätedaten wurden gespeichert.');
    }

    /** @return array{0: ?string, 1: ?string} [mac, ip] — mindestens eins gesetzt. */
    private function kennung(Request $request): array
    {
        $mac = mb_strtolower(trim((string) $request->input('mac')));
        $mac = preg_match(self::MAC_MUSTER, $mac) ? str_replace('-', ':', $mac) : null;

        $ip = trim((string) $request->input('ip'));
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;

        abort_if($mac === null && $ip === null, 404, 'Gerät ohne gültige Kennung.');

        return [$mac, $ip];
    }

    private function finden(?string $mac, ?string $ip): ?Geraet
    {
        if ($mac !== null && ($treffer = Geraet::where('mac', $mac)->first()) !== null) {
            return $treffer;
        }

        return $ip !== null
            ? Geraet::whereNull('mac')->where('ip', $ip)->first()
            : null;
    }

    /** Rücksprungziel aus der Adresszeile — nur modul-eigene Seiten erlaubt. */
    private function zurueck(Request $request): string
    {
        $ziel = (string) $request->input('zurueck');

        return str_starts_with($ziel, '/modules/netzwerk')
            ? $ziel
            : route('module.netzwerk.geraete', absolute: false);
    }
}
