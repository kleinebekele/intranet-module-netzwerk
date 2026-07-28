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
            ->item('geraete', 'Geräte', 'module.netzwerk.geraete', icon: 'list')
            ->item('statistik', 'Statistik', 'module.netzwerk.statistik', icon: 'chart')
            ->item('typen', 'Gerätetypen', 'module.netzwerk.typen', icon: 'category')
            ->item('standorte', 'Standorte', 'module.netzwerk.standorte', icon: 'door');
    }

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom($this->moduleBasePath().'/config/netzwerk.php', 'netzwerk');

        // Die MSSQL-Quelle als Laravel-Connection. Der Name kommt aus der
        // Config, damit er bei Bedarf zur Datenquelle passen darf.
        config(['database.connections.'.Netzwerk::connection() => config('netzwerk.mssql')]);

        // Kür: Ist die Ekkon-Basis installiert, meldet das Modul seinen
        // Alarm-Task an (Netzwerk/Alarme). Ohne Ekkon fehlt nur der Task —
        // alles andere läuft unverändert (weiche Abhängigkeit, siehe suggest).
        // Der Task braucht Task-Einstellungen (Basis ≥ v1.1) — eine ältere
        // Basis bekommt ihn gar nicht erst, statt am fehlenden einstellung()
        // zu scheitern.
        if (class_exists(\Intranet\Modules\Ekkon\Support\TaskRegistry::class)
                && method_exists(\Intranet\Modules\Ekkon\Tasks\EkkonTask::class, 'einstellung')) {
            $this->app->singletonIf(\Intranet\Modules\Ekkon\Support\TaskRegistry::class);
            $this->app->make(\Intranet\Modules\Ekkon\Support\TaskRegistry::class)->addSource(
                $this->moduleBasePath().'/src/Tasks',
                __NAMESPACE__.'\\Tasks',
                'do1emu/module-netzwerk',
            );
        }
    }
}
