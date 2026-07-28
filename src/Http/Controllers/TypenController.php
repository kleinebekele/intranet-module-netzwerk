<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Models\Geraetetyp;

/**
 * CRUD der Gerätetypen. Wer die Seite sehen darf, steuern wie überall die
 * Rollen des Menüpunkts (Standard: nur Admins).
 */
class TypenController extends Controller
{
    public function index(): View
    {
        return view('netzwerk::typen', [
            'typen' => Geraetetyp::withCount('geraete')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $daten = $request->validate(
            ['name' => ['required', 'string', 'max:100', 'unique:netzwerk_geraetetypen,name']],
            [], ['name' => 'Name'],
        );

        Geraetetyp::create($daten);

        return redirect()->route('module.netzwerk.typen')
            ->with('status', 'Gerätetyp „'.$daten['name'].'" wurde angelegt.');
    }

    public function update(Request $request, Geraetetyp $typ): RedirectResponse
    {
        $daten = $request->validate(
            ['name' => ['required', 'string', 'max:100',
                Rule::unique('netzwerk_geraetetypen', 'name')->ignore($typ->id)]],
            [], ['name' => 'Name'],
        );

        $typ->update($daten);

        return redirect()->route('module.netzwerk.typen')
            ->with('status', 'Gerätetyp „'.$typ->name.'" wurde gespeichert.');
    }

    public function destroy(Geraetetyp $typ): RedirectResponse
    {
        $name = $typ->name;
        $typ->delete();    // Geräte behalten ihre Zeile, der Typ wird dort NULL

        return redirect()->route('module.netzwerk.typen')
            ->with('status', 'Gerätetyp „'.$name.'" wurde gelöscht.');
    }
}
