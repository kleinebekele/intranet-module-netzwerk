<?php

return [

    /*
     * Name, unter dem die MSSQL-Verbindung als Laravel-Connection registriert
     * wird. Code außerhalb dieses Moduls holt ihn über Netzwerk::connection(),
     * nicht von hier.
     */
    'connection' => env('NETZWERK_DB_CONNECTION', 'netzwerk'),

    /*
     * MSSQL-Schema, in dem die network_*-Tabellen liegen. Historisch `Ekkon3`
     * (dort schreibt der Collector seit dem ersten Tag) – technisch hängt daran
     * nichts, per env umziehbar.
     */
    'schema' => env('NETZWERK_DB_SCHEMA', 'Ekkon3'),

    /*
     * Ab wie vielen Minuten ohne Lebenszeichen gilt ein Gerät als offline?
     * Der Collector läuft alle 5 Minuten; der Standard verzeiht zwei
     * verpasste Läufe.
     */
    'offline_ab_minuten' => (int) env('NETZWERK_OFFLINE_AB_MINUTEN', 15),

    /*
     * NUR für die lokale Entwicklung: Mit NETZWERK_DEMO=true zeigt die Karte
     * erfundene Beispieldaten statt der MSSQL-Quelle (die aus der Entwicklungs-
     * umgebung meist nicht erreichbar ist). Niemals auf einem Server setzen.
     */
    'demo' => (bool) env('NETZWERK_DEMO', false),

    /*
     * Die MSSQL-Quelle, in die der Collector (Raspberry Pi) schreibt. ZWEI WEGE –
     * die installierte PHP-Erweiterung entscheidet, welcher geht:
     *
     *  a) Nativ (pdo_sqlsrv):
     *       NETZWERK_DB_HOST=sql.example.local
     *       NETZWERK_DB_PORT=1433
     *       NETZWERK_DB_DATABASE=meinedb
     *       NETZWERK_DB_USERNAME=leser
     *       NETZWERK_DB_PASSWORD=...
     *
     *  b) Über ODBC (pdo_odbc + Microsoft ODBC Driver). Sobald NETZWERK_DB_DSN
     *     gesetzt ist, gilt dieser Weg und Host/Port werden ignoriert:
     *       NETZWERK_DB_DSN="Driver={ODBC Driver 18 for SQL Server};Server=host,1433;Database=meinedb;TrustServerCertificate=yes"
     *       NETZWERK_DB_USERNAME=leser
     *       NETZWERK_DB_PASSWORD=...
     *
     * Zeigt die Quelle auf denselben Server wie eine andere Connection (z. B. die
     * der Ekkon-Basis), ist das ein Deployment-Detail – dieses Modul hat keinerlei
     * Code-Abhängigkeit dorthin.
     */
    'mssql' => [
        'driver' => 'sqlsrv',

        // Nur mit hinterlegtem DSN geht Laravel den ODBC-Weg.
        'odbc' => env('NETZWERK_DB_DSN', '') !== '',
        'odbc_datasource_name' => env('NETZWERK_DB_DSN', ''),

        'host' => env('NETZWERK_DB_HOST', ''),
        'port' => env('NETZWERK_DB_PORT', 1433),
        'database' => env('NETZWERK_DB_DATABASE', ''),
        'username' => env('NETZWERK_DB_USERNAME', ''),
        'password' => env('NETZWERK_DB_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',

        // Selbstsigniertes Server-Zertifikat akzeptieren – nur für den nativen
        // Weg; beim ODBC-Weg gehört TrustServerCertificate in den DSN.
        'trust_server_certificate' => env('NETZWERK_DB_TRUST_CERT', false),
    ],

];
