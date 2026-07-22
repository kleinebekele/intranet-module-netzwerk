<?php

namespace Intranet\Modules\Netzwerk;

/**
 * Öffentliche API des Netzwerk-Moduls für fremden Code.
 *
 * Wer von außen etwas über die Netzwerk-Datenquelle wissen will, fragt hier –
 * nicht die interne Config-Struktur (config('netzwerk.…')), sonst bricht jede
 * Umbenennung dort fremde Pakete.
 */
class Netzwerk
{
    /**
     * Name der Laravel-Connection zur MSSQL-Quelle:
     * DB::connection(Netzwerk::connection()).
     */
    public static function connection(): string
    {
        return (string) config('netzwerk.connection', 'netzwerk');
    }

    /**
     * MSSQL-Schema der network_*-Tabellen, z. B. für "SELECT … FROM {schema}.network_devices".
     */
    public static function schema(): string
    {
        return (string) config('netzwerk.schema', 'Ekkon3');
    }

    /**
     * Sind überhaupt Zugangsdaten hinterlegt?
     *
     * Ohne Konfiguration zeigen die Seiten einen freundlichen Hinweis statt
     * Verbindungsfehlern – wichtig für frisch installierte Instanzen.
     */
    public static function konfiguriert(): bool
    {
        $config = (array) config('netzwerk.mssql', []);

        return ($config['odbc_datasource_name'] ?? '') !== '' || ($config['host'] ?? '') !== '';
    }
}
