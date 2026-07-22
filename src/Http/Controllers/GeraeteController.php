<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Support\GeraeteListe;

class GeraeteController extends Controller
{
    public function index(GeraeteListe $liste): View
    {
        return view('netzwerk::index', $liste->geraete());
    }
}
