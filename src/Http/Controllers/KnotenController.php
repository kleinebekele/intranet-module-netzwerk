<?php

namespace Intranet\Modules\Netzwerk\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Intranet\Modules\Netzwerk\Support\KnotenDetail;

class KnotenController extends Controller
{
    public function show(int $id, KnotenDetail $detail): View
    {
        $daten = $detail->detail($id);
        abort_if($daten === null, 404);

        return view('netzwerk::knoten-detail', $daten);
    }
}
