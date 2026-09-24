<?php

use Illuminate\Support\Facades\Route;
use Intranet\Modules\Netzwerk\Http\Controllers\DhcpController;
use Intranet\Modules\Netzwerk\Http\Controllers\GeraetController;
use Intranet\Modules\Netzwerk\Http\Controllers\GeraeteController;
use Intranet\Modules\Netzwerk\Http\Controllers\KarteController;
use Intranet\Modules\Netzwerk\Http\Controllers\KnotenController;
use Intranet\Modules\Netzwerk\Http\Controllers\StandorteController;
use Intranet\Modules\Netzwerk\Http\Controllers\StatistikController;
use Intranet\Modules\Netzwerk\Http\Controllers\TypenController;
use Intranet\Modules\Netzwerk\Http\Controllers\WlanGruppenController;

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
        Route::get('/statistik', [StatistikController::class, 'index'])->name('statistik');

        // DHCP: Adresskarte, Verlauf, Belegungen (Task Netzwerk/Dhcp). Unter dhcp.*
        // benannt, damit die Aktionen die Rollen des Menüpunkts erben.
        Route::get('/dhcp', [DhcpController::class, 'index'])->name('dhcp');
        Route::post('/dhcp/manuell', [DhcpController::class, 'manuellSpeichern'])->name('dhcp.manuell');
        Route::post('/dhcp/manuell/entfernen', [DhcpController::class, 'manuellEntfernen'])->name('dhcp.manuell.entfernen');
        Route::get('/knoten/{id}', [KnotenController::class, 'show'])->whereNumber('id')->name('knoten');
        // Ausblenden = gehört nicht dazu (z. B. LLDP-sprechender Virtualisierungs-Host):
        // weg von Karte und Alarm; mit einblenden=1 kommt er zurück (Liste auf der Karte).
        Route::post('/knoten/{id}/ausblenden', [KnotenController::class, 'ausblenden'])->whereNumber('id')->name('knoten.ausblenden');

        // Überwachte WLAN-Gruppen des Alarm-Tasks — Bedienung liegt auf der
        // Ekkon-Task-Seite (Einstellung vom Typ "view"), die Routen hier.
        Route::post('/wlan-gruppen', [WlanGruppenController::class, 'hinzufuegen'])->name('wlan-gruppen.hinzufuegen');
        Route::post('/wlan-gruppen/{index}/entfernen', [WlanGruppenController::class, 'entfernen'])->whereNumber('index')->name('wlan-gruppen.entfernen');

        // Pflege-Formular je Gerät (Typ/Standort/Info); Kennung = MAC/IP in
        // der Adresszeile. Erbt wie /knoten die Rollen des Karten-Menüpunkts.
        Route::get('/geraet', [GeraetController::class, 'bearbeiten'])->name('geraet');
        Route::post('/geraet', [GeraetController::class, 'speichern'])->name('geraet.speichern');

        // CRUD Gerätetypen + Standorte — je eigener Menüpunkt (eigene Rollen).
        Route::get('/typen', [TypenController::class, 'index'])->name('typen');
        Route::post('/typen', [TypenController::class, 'store'])->name('typen.store');
        Route::put('/typen/{typ}', [TypenController::class, 'update'])->whereNumber('typ')->name('typen.update');
        Route::delete('/typen/{typ}', [TypenController::class, 'destroy'])->whereNumber('typ')->name('typen.destroy');
        Route::get('/standorte', [StandorteController::class, 'index'])->name('standorte');
        Route::post('/standorte/gebaeude', [StandorteController::class, 'gebaeudeStore'])->name('standorte.gebaeude.store');
        Route::put('/standorte/gebaeude/{gebaeude}', [StandorteController::class, 'gebaeudeUpdate'])->whereNumber('gebaeude')->name('standorte.gebaeude.update');
        Route::delete('/standorte/gebaeude/{gebaeude}', [StandorteController::class, 'gebaeudeDestroy'])->whereNumber('gebaeude')->name('standorte.gebaeude.destroy');
        Route::post('/standorte/gebaeude/{gebaeude}/stockwerke', [StandorteController::class, 'stockwerkStore'])->whereNumber('gebaeude')->name('standorte.stockwerke.store');
        Route::put('/standorte/stockwerke/{stockwerk}', [StandorteController::class, 'stockwerkUpdate'])->whereNumber('stockwerk')->name('standorte.stockwerke.update');
        Route::delete('/standorte/stockwerke/{stockwerk}', [StandorteController::class, 'stockwerkDestroy'])->whereNumber('stockwerk')->name('standorte.stockwerke.destroy');
        Route::post('/standorte/gebaeude/{gebaeude}/raeume', [StandorteController::class, 'raumStore'])->whereNumber('gebaeude')->name('standorte.raeume.store');
        Route::put('/standorte/raeume/{raum}', [StandorteController::class, 'raumUpdate'])->whereNumber('raum')->name('standorte.raeume.update');
        Route::delete('/standorte/raeume/{raum}', [StandorteController::class, 'raumDestroy'])->whereNumber('raum')->name('standorte.raeume.destroy');
    });
