<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Intranet\Modules\Netzwerk\Support\WlanGruppen;

/**
 * Bedienung der überwachten WLAN-Gruppen auf der Ekkon-Task-Seite von
 * Netzwerk/Alarme (Einstellung vom Typ „view"): Gruppe hinzufügen (eine oder
 * mehrere SSIDs + Schwelle), Gruppe entfernen. Zurück geht es immer auf die
 * Task-Seite. Rollen: wie das Netzwerk-Modul (Menüpunkt Karte).
 */
class WlanGruppenController extends Controller
{
    public function hinzufuegen(Request $request): RedirectResponse
    {
        $daten = $request->validate([
            'ssids' => ['required', 'array', 'min:1'],
            'ssids.*' => ['string', 'max:64', 'regex:/^[^;+=]+$/'],
            'schwelle' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [
            'ssids.required' => 'Bitte mindestens ein WLAN auswählen.',
            'ssids.*.regex' => 'SSIDs dürfen kein ; + oder = enthalten.',
        ], ['ssids' => 'WLANs', 'schwelle' => 'Schwelle']);

        $ssids = array_values(array_unique(array_filter(array_map('trim', $daten['ssids']), fn ($s) => $s !== '')));
        $gruppen = WlanGruppen::gespeichert();
        $gruppen[] = ['ssids' => $ssids, 'schwelle' => (int) $daten['schwelle']];
        WlanGruppen::speichern($gruppen);

        return $this->zurueck('WLAN-Gruppe „'.implode(' + ', $ssids).'" überwacht (Andrang ab mehr als '.(int) $daten['schwelle'].' Geräten).');
    }

    public function entfernen(int $index): RedirectResponse
    {
        $gruppen = WlanGruppen::gespeichert();
        if (! isset($gruppen[$index])) {
            return $this->zurueck('Diese Gruppe gibt es nicht mehr.');
        }
        $weg = $gruppen[$index];
        unset($gruppen[$index]);
        WlanGruppen::speichern(array_values($gruppen));

        return $this->zurueck('WLAN-Gruppe „'.implode(' + ', $weg['ssids']).'" wird nicht mehr überwacht.');
    }

    private function zurueck(string $meldung): RedirectResponse
    {
        return redirect()
            ->route('module.ekkon.task.show', explode('/', WlanGruppen::TASK_KEY, 2))
            ->with('status', $meldung);
    }
}
