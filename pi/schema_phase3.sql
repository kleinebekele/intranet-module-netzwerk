-- Phase 3: Gerät-zu-Port-Zuordnung (FDB) + WLAN-Vorbereitung.
-- Erweitert network_devices um die Verortung und legt die Staging-Tabelle
-- für die FDB-Zuordnungen an. Mehrfach ausführbar (prüft, was fehlt).
-- Platzhalter __DB__/__SCHEMA__ ersetzt der Collector (--init-db).

USE [__DB__];
GO

-- network_devices (aus netscan, Phase 0) um die Verortung erweitern:
-- an welchem Node/Port (LAN) bzw. AP/SSID (WLAN) hängt das Gerät?
IF COL_LENGTH('__SCHEMA__.network_devices', 'node_id') IS NULL
    ALTER TABLE __SCHEMA__.network_devices ADD node_id INT NULL;
IF COL_LENGTH('__SCHEMA__.network_devices', 'port_name') IS NULL
    ALTER TABLE __SCHEMA__.network_devices ADD port_name NVARCHAR(160) NULL;
IF COL_LENGTH('__SCHEMA__.network_devices', 'verbunden_via') IS NULL
    ALTER TABLE __SCHEMA__.network_devices ADD verbunden_via NVARCHAR(8) NULL;   -- lan | wlan
IF COL_LENGTH('__SCHEMA__.network_devices', 'ssid') IS NULL
    ALTER TABLE __SCHEMA__.network_devices ADD ssid NVARCHAR(64) NULL;
IF COL_LENGTH('__SCHEMA__.network_devices', 'zugeordnet_am') IS NULL
    ALTER TABLE __SCHEMA__.network_devices ADD zugeordnet_am DATETIME2 NULL;
GO

-- Staging für die FDB-Zuordnungen: je Zeile "MAC x hängt an Node y, Port z".
-- Der Collector hat Uplink-Ports und Node-eigene MACs bereits herausgefiltert.
IF OBJECT_ID('__SCHEMA__.network_fdb_stage', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_fdb_stage (
    node_matchKey NVARCHAR(64)  NULL,
    port_name     NVARCHAR(160) NULL,
    mac           NVARCHAR(20)  NULL
);
GO

-- Staging für die WLAN-Zuordnungen (WC7500): je Zeile "Client-MAC x ist an
-- AP y (SSID z) eingebucht". Der AP wird über seine IP dem Node zugeordnet.
IF OBJECT_ID('__SCHEMA__.network_wlan_stage', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_wlan_stage (
    mac     NVARCHAR(20)  NULL,
    ap_ip   NVARCHAR(45)  NULL,
    ap_name NVARCHAR(160) NULL,
    ssid    NVARCHAR(64)  NULL
);
GO
