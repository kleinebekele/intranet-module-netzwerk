<?php

namespace Intranet\Modules\Netzwerk;

use App\Modules\Support\ModuleManifest;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Anmelde-Klasse des Netzwerk-Moduls.
 *
 * Das Modul zeigt das Netzwerk-Inventar, das ein externer Collector (bei uns:
 * ein Raspberry Pi mit nmap/SNMP) in eine MSSQL-Datenbank schreibt. Es liest
 * ausschließlich – erhoben wird nichts von hier aus, und Zugangsdaten zu
 * Netzwerkgeräten kennt das Intranet nicht.
 *
 * Über das Übliche hinaus passiert hier nur eins: Die MSSQL-Quelle wird als
 * eigene Laravel-Connection registriert (Standardname `netzwerk`), damit das
 * Modul von keinem anderen Modul abhängt.
 */
class NetzwerkServiceProvider extends ModuleServiceProvider
{
    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('netzwerk', 'Netzwerk', icon: 'network')
            ->item('index', 'Karte', 'module.netzwerk.index', icon: 'network')
            ->item('geraete', 'Geräte', 'module.netzwerk.geraete', icon: 'list');
    }

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom($this->moduleBasePath().'/config/netzwerk.php', 'netzwerk');

        // Die MSSQL-Quelle als Laravel-Connection. Der Name kommt aus der
        // Config, damit er bei Bedarf zur Datenquelle passen darf.
        config(['database.connections.'.Netzwerk::connection() => config('netzwerk.mssql')]);
    }
}
