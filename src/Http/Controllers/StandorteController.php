<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Models\Gebaeude;
use Intranet\Modules\Netzwerk\Models\Raum;
use Intranet\Modules\Netzwerk\Models\Stockwerk;

/**
 * Standort-Hierarchie: Gebäude -> Stockwerke -> Räume, jede Ebene einzeln
 * pflegbar (ein Raum kann auch direkt am Gebäude hängen). Sichtbarkeit über
 * die Rollen des Menüpunkts (Standard: nur Admins).
 */
class StandorteController extends Controller
{
    public function index(): View
    {
        return view('netzwerk::standorte', [
            'gebaeude' => Gebaeude::withCount('geraete')
                ->with([
                    'stockwerke' => fn ($q) => $q->withCount('geraete')->orderBy('id')
                        ->with(['raeume' => fn ($r) => $r->withCount('geraete')->orderBy('name')]),
                    'raeume' => fn ($q) => $q->whereNull('stockwerk_id')->withCount('geraete')->orderBy('name'),
                ])
                ->orderBy('name')->get(),
        ]);
    }

    // ── Gebäude ──────────────────────────────────────────────────────────────

    public function gebaeudeStore(Request $request): RedirectResponse
    {
        $daten = $request->validate(
            ['name' => ['required', 'string', 'max:100', 'unique:netzwerk_gebaeude,name']],
            [], ['name' => 'Gebäude'],
        );
        Gebaeude::create($daten);

        return $this->zurueck('Gebäude „'.$daten['name'].'" wurde angelegt.');
    }

    public function gebaeudeUpdate(Request $request, Gebaeude $gebaeude): RedirectResponse
    {
        $daten = $request->validate(
            ['name' => ['required', 'string', 'max:100',
                Rule::unique('netzwerk_gebaeude', 'name')->ignore($gebaeude->id)]],
            [], ['name' => 'Gebäude'],
        );
        $gebaeude->update($daten);

        return $this->zurueck('Gebäude wurde gespeichert.');
    }

    public function gebaeudeDestroy(Gebaeude $gebaeude): RedirectResponse
    {
        $gebaeude->delete();    // Stockwerke/Räume kaskadieren, Geräte -> Standort NULL

        return $this->zurueck('Gebäude wurde samt Stockwerken und Räumen gelöscht.');
    }

    // ── Stockwerke ───────────────────────────────────────────────────────────

    public function stockwerkStore(Request $request, Gebaeude $gebaeude): RedirectResponse
    {
        $daten = $request->validate(
            ['name' => ['required', 'string', 'max:100',
                Rule::unique('netzwerk_stockwerke', 'name')->where('gebaeude_id', $gebaeude->id)]],
            [], ['name' => 'Stockwerk'],
        );
        $gebaeude->stockwerke()->create($daten);

        return $this->zurueck('Stockwerk „'.$daten['name'].'" wurde angelegt.');
    }

    public function stockwerkUpdate(Request $request, Stockwerk $stockwerk): RedirectResponse
    {
        $daten = $request->validate(
            ['name' => ['required', 'string', 'max:100',
                Rule::unique('netzwerk_stockwerke', 'name')
                    ->where('gebaeude_id', $stockwerk->gebaeude_id)->ignore($stockwerk->id)]],
            [], ['name' => 'Stockwerk'],
        );
        $stockwerk->update($daten);

        return $this->zurueck('Stockwerk wurde gespeichert.');
    }

    public function stockwerkDestroy(Stockwerk $stockwerk): RedirectResponse
    {
        $stockwerk->delete();   // Räume kaskadieren, Geräte -> Stockwerk/Raum NULL

        return $this->zurueck('Stockwerk wurde samt Räumen gelöscht.');
    }

    // ── Räume ────────────────────────────────────────────────────────────────

    /** Legt einen oder mehrere Räume an — Kommas trennen ("R1, R2, R3"). */
    public function raumStore(Request $request, Gebaeude $gebaeude): RedirectResponse
    {
        $daten = $request->validate(
            [
                'name' => ['required', 'string', 'max:500'],
                'stockwerk_id' => ['nullable', 'integer',
                    Rule::exists('netzwerk_stockwerke', 'id')->where('gebaeude_id', $gebaeude->id)],
            ],
            [], ['name' => 'Raum', 'stockwerk_id' => 'Stockwerk'],
        );

        $namen = collect(explode(',', $daten['name']))
            ->map(fn ($n) => trim($n))
            ->filter(fn ($n) => $n !== '' && mb_strlen($n) <= 100)
            ->unique()
            ->values();
        foreach ($namen as $name) {
            $gebaeude->raeume()->firstOrCreate([
                'stockwerk_id' => $daten['stockwerk_id'] ?? null,
                'name' => $name,
            ]);
        }

        return $this->zurueck($namen->count() === 1
            ? 'Raum „'.$namen->first().'" wurde angelegt.'
            : $namen->count().' Räume wurden angelegt.');
    }

    public function raumUpdate(Request $request, Raum $raum): RedirectResponse
    {
        $daten = $request->validate(
            ['name' => ['required', 'string', 'max:100']],
            [], ['name' => 'Raum'],
        );
        $raum->update($daten);

        return $this->zurueck('Raum wurde gespeichert.');
    }

    public function raumDestroy(Raum $raum): RedirectResponse
    {
        $raum->delete();        // Geräte -> Raum NULL

        return $this->zurueck('Raum wurde gelöscht.');
    }

    private function zurueck(string $meldung): RedirectResponse
    {
        return redirect()->route('module.netzwerk.standorte')->with('status', $meldung);
    }
}
