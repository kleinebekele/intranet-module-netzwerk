<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Support\KartenDaten;

class KarteController extends Controller
{
    public function index(KartenDaten $daten): View
    {
        return view('netzwerk::karte', $daten->karte());
    }
}
