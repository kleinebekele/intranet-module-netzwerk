<?php

use Illuminate\Support\Facades\Route;
use Intranet\Modules\Netzwerk\Http\Controllers\GeraeteController;

/*
 | Routen des Netzwerk-Moduls.
 |
 | Konvention (siehe MODULES.md des Core):
 |  - URL-Präfix:  modules/netzwerk
 |  - Namen:       module.netzwerk.*
 |  - Middleware:  'web' + 'auth'
 |
 | Wer die Seiten sehen darf, steuern die Rollen des Menüpunkts (Core:
 | Verwaltung → Module → Netzwerk). Standard: nur Admins – die Übersicht zeigt
 | Netz-Interna (IPs, MACs, Hostnamen) und gehört nicht in jedermanns Hände.
*/
Route::middleware(['web', 'auth'])
    ->prefix('modules/netzwerk')
    ->name('module.netzwerk.')
    ->group(function (): void {
        Route::get('/', [GeraeteController::class, 'index'])->name('index');
    });
