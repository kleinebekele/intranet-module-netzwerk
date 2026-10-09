<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Support\WlanDaten;

class WlanController extends Controller
{
    public function index(Request $request, WlanDaten $daten): View
    {
        return view('netzwerk::wlan', $daten->auswertung((string) $request->query('zeitraum', '7t')) + [
            'zeitraeume' => WlanDaten::ZEITRAEUME,
        ]);
    }
}
