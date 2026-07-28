<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Models\Gebaeude;
use Intranet\Modules\Netzwerk\Models\Geraet;
use Intranet\Modules\Netzwerk\Models\Geraetetyp;
use Intranet\Modules\Netzwerk\Models\Raum;
use Intranet\Modules\Netzwerk\Models\Stockwerk;
use Intranet\Modules\Netzwerk\Support\GeraeteListe;

/**
 * Pflege-Formular je Gerät: Typ, Standort, Info.
 *
 * Das Gerät kommt aus der (fremden, read-only) Inventar-DB und wird hier nur
 * über MAC bzw. IP aus der Adresszeile identifiziert — beides wird streng
 * validiert, damit kein Müll als Schlüssel in der Pflege-Tabelle landet.
 *
 * Der Standort ist EIN Auswahlfeld über die ganze Hierarchie; die Werte sind
 * kodiert (g<id> = Gebäude, s<id> = Stockwerk, r<id> = Raum) und werden beim
 * Speichern normalisiert: ein Raum setzt auch Stockwerk und Gebäude.
 */
class GeraetController extends Controller
{
    private const MAC_MUSTER = '/^[0-9a-fA-F]{2}([:-][0-9a-fA-F]{2}){5}$/';

    public function bearbeiten(Request $request, GeraeteListe $liste): View
    {
        [$mac, $ip] = $this->kennung($request);
        $geraet = $this->finden($mac, $ip);
        $inventar = $liste->finden($mac, $ip);

        return view('netzwerk::geraet-bearbeiten', [
            'mac' => $mac,
            'ip' => $ip,
            'inventar' => $inventar,
            'anzeige' => $inventar?->hostname
                ?? (trim((string) $request->query('anzeige')) ?: ($ip ?? $mac)),
            'zurueck' => $this->zurueck($request),
            'geraet' => $geraet,
            'erkannt' => trim((string) $request->query('erkannt')) ?: null,
            'typen' => Geraetetyp::orderBy('name')->get(),
            'standortOptionen' => $this->standortOptionen(),
            'standortWert' => $geraet === null ? '' : match (true) {
                $geraet->raum_id !== null => 'r'.$geraet->raum_id,
                $geraet->stockwerk_id !== null => 's'.$geraet->stockwerk_id,
                $geraet->gebaeude_id !== null => 'g'.$geraet->gebaeude_id,
                default => '',
            },
        ]);
    }

    public function speichern(Request $request): RedirectResponse
    {
        [$mac, $ip] = $this->kennung($request);

        $daten = $request->validate(
            [
                'geraetetyp_id' => ['nullable', 'integer', 'exists:netzwerk_geraetetypen,id'],
                'standort' => ['nullable', 'string', 'regex:/^[gsr][0-9]+$/'],
                'info' => ['nullable', 'string', 'max:2000'],
            ],
            [], ['geraetetyp_id' => 'Gerätetyp', 'standort' => 'Standort', 'info' => 'Info'],
        );
        $daten['info'] = trim((string) ($daten['info'] ?? '')) ?: null;
        [$gebaeudeId, $stockwerkId, $raumId] = $this->standortAufloesen($daten['standort'] ?? null);

        $geraet = $this->finden($mac, $ip);

        if (($daten['geraetetyp_id'] ?? null) === null && $gebaeudeId === null && $daten['info'] === null) {
            // Alles geleert -> Zeile weg, statt leere Hüllen zu sammeln.
            $geraet?->delete();
        } else {
            $geraet ??= new Geraet;
            // MAC nachziehen (Zeile stammt ggf. aus IP-Zeiten), IP aktuell halten.
            $geraet->mac ??= $mac;
            $geraet->ip = $ip ?? $geraet->ip;
            $geraet->fill([
                'geraetetyp_id' => $daten['geraetetyp_id'] ?? null,
                'gebaeude_id' => $gebaeudeId,
                'stockwerk_id' => $stockwerkId,
                'raum_id' => $raumId,
                'info' => $daten['info'],
            ])->save();
        }

        return redirect($this->zurueck($request))
            ->with('status', 'Gerätedaten wurden gespeichert.');
    }

    /**
     * Auswahlliste über die ganze Hierarchie, gruppiert je Gebäude.
     *
     * @return list<array{label: string, optionen: list<array{wert: string, text: string}>}>
     */
    private function standortOptionen(): array
    {
        $einzug = "\u{00A0}\u{00A0}\u{00A0}";

        return Gebaeude::with([
            'stockwerke' => fn ($q) => $q->orderBy('id')->with(['raeume' => fn ($r) => $r->orderBy('name')]),
            'raeume' => fn ($q) => $q->whereNull('stockwerk_id')->orderBy('name'),
        ])->orderBy('name')->get()->map(function (Gebaeude $geb) use ($einzug) {
            $optionen = [['wert' => 'g'.$geb->id, 'text' => $geb->name.' (gesamt)']];
            foreach ($geb->raeume as $raum) {
                $optionen[] = ['wert' => 'r'.$raum->id, 'text' => $einzug.$raum->name];
            }
            foreach ($geb->stockwerke as $stockwerk) {
                $optionen[] = ['wert' => 's'.$stockwerk->id, 'text' => $einzug.$stockwerk->name];
                foreach ($stockwerk->raeume as $raum) {
                    $optionen[] = ['wert' => 'r'.$raum->id, 'text' => $einzug.$einzug.$stockwerk->name.' · '.$raum->name];
                }
            }

            return ['label' => $geb->name, 'optionen' => $optionen];
        })->all();
    }

    /** @return array{0: ?int, 1: ?int, 2: ?int} [gebaeude_id, stockwerk_id, raum_id], normalisiert. */
    private function standortAufloesen(?string $code): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return [null, null, null];
        }

        $id = (int) substr($code, 1);
        try {
            return match ($code[0]) {
                'g' => [Gebaeude::findOrFail($id)->id, null, null],
                's' => (fn (Stockwerk $s) => [$s->gebaeude_id, $s->id, null])(Stockwerk::findOrFail($id)),
                'r' => (fn (Raum $r) => [$r->gebaeude_id, $r->stockwerk_id, $r->id])(Raum::findOrFail($id)),
            };
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(
                ['standort' => 'Der gewählte Standort existiert nicht mehr — Seite neu laden.']);
        }
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
