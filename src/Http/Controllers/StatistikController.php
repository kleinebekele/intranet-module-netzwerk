<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Support\StatistikDaten;

class StatistikController extends Controller
{
    public function index(Request $request, StatistikDaten $daten): View
    {
        return view('netzwerk::statistik', $daten->statistik(
            $request->filled('knoten') ? (int) $request->query('knoten') : null,
            (string) $request->query('zeitraum', '24h'),
        ));
    }
}
