<?php

use Illuminate\Support\Facades\Route;
use Intranet\Modules\Netzwerk\Http\Controllers\GeraeteController;
use Intranet\Modules\Netzwerk\Http\Controllers\KarteController;

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
        // Die Karte ist die Startseite des Moduls (paramloser .index = Anker
        // fürs Rollen-Gating), die Geräteliste eine Unterseite.
        Route::get('/', [KarteController::class, 'index'])->name('index');
        Route::get('/geraete', [GeraeteController::class, 'index'])->name('geraete');
    });
