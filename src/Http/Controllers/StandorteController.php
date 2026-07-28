<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Models\Standort;

/**
 * CRUD der Standorte (Gebäude, optional Stockwerk und Raum). Sichtbarkeit
 * über die Rollen des Menüpunkts (Standard: nur Admins).
 */
class StandorteController extends Controller
{
    public function index(): View
    {
        return view('netzwerk::standorte', [
            'standorte' => Standort::withCount('geraete')
                ->orderBy('gebaeude')->orderBy('stockwerk')->orderBy('raum')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $standort = Standort::create($this->pruefen($request));

        return redirect()->route('module.netzwerk.standorte')
            ->with('status', 'Standort „'.$standort->bezeichnung().'" wurde angelegt.');
    }

    public function update(Request $request, Standort $standort): RedirectResponse
    {
        $standort->update($this->pruefen($request));

        return redirect()->route('module.netzwerk.standorte')
            ->with('status', 'Standort „'.$standort->bezeichnung().'" wurde gespeichert.');
    }

    public function destroy(Standort $standort): RedirectResponse
    {
        $name = $standort->bezeichnung();
        $standort->delete();    // Geräte behalten ihre Zeile, der Standort wird NULL

        return redirect()->route('module.netzwerk.standorte')
            ->with('status', 'Standort „'.$name.'" wurde gelöscht.');
    }

    /** @return array{gebaeude: string, stockwerk: ?string, raum: ?string} */
    private function pruefen(Request $request): array
    {
        $daten = $request->validate(
            [
                'gebaeude' => ['required', 'string', 'max:100'],
                'stockwerk' => ['nullable', 'string', 'max:100'],
                'raum' => ['nullable', 'string', 'max:100'],
            ],
            [], ['gebaeude' => 'Gebäude', 'stockwerk' => 'Stockwerk', 'raum' => 'Raum'],
        );

        return [
            'gebaeude' => $daten['gebaeude'],
            'stockwerk' => $daten['stockwerk'] ?? null,
            'raum' => $daten['raum'] ?? null,
        ];
    }
}
