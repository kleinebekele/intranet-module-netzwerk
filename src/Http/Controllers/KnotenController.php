<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Models\AusgeblendeterKnoten;
use Intranet\Modules\Netzwerk\Models\KnotenStatus;
use Intranet\Modules\Netzwerk\Support\KnotenDetail;

class KnotenController extends Controller
{
    public function show(int $id, KnotenDetail $detail): View
    {
        $daten = $detail->detail($id);
        abort_if($daten === null, 404);

        return view('netzwerk::knoten-detail', $daten);
    }

    /**
     * Knoten ausblenden (gehört nicht zur überwachten Infrastruktur) bzw. mit
     * einblenden=1 wieder zurückholen. Gemerkt wird der matchKey des
     * Collectors in der Instanz-DB; die MSSQL bleibt unberührt.
     */
    public function ausblenden(int $id, Request $request, KnotenDetail $detail): RedirectResponse
    {
        $daten = $detail->detail($id);
        abort_if($daten === null, 404);
        $knoten = $daten['knoten'];
        $anzeige = $knoten->name ?? $knoten->ip ?? 'Knoten';

        if ($request->boolean('einblenden')) {
            AusgeblendeterKnoten::where('matchkey', $knoten->schluessel)->delete();

            return redirect()->route('module.netzwerk.knoten', $id)
                ->with('status', '„'.$anzeige.'" wird wieder eingeblendet.');
        }

        AusgeblendeterKnoten::updateOrCreate(['matchkey' => $knoten->schluessel], [
            'name' => $knoten->name,
            'ip' => $knoten->ip,
            'art' => $knoten->art,
        ]);
        // Das Alarm-Gedächtnis vergisst ihn mit — sonst käme beim Einblenden
        // ein „neu entdeckt" für einen alten Bekannten.
        KnotenStatus::where('matchkey', $knoten->schluessel)->delete();

        return redirect()->route('module.netzwerk.index')
            ->with('status', '„'.$anzeige.'" ist ausgeblendet – kein Alarm mehr, nicht auf der Karte. Rückholen: Abschnitt „Ausgeblendet" unten auf der Karte.');
    }
}
