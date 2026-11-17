-- Phase 7: Syslog-Ereignisse des WLAN-Controllers (2026-11-17). Der Empfänger
-- netmon_syslog.py schreibt Tagesdateien; `netmon_collector.py --syslog-import`
-- liest sie fortlaufend, zerlegt die Zeilen und lädt sie hierher:
--   art = rssi      Load Balancing [Bad RSSI]: Client abgewiesen (Signal unter Schwelle)
--   art = alarm     jeder andere Controller-Alarm (Kategorie, Stufe, Text)
--   art = discover | offer | request | ack | nak | ...   DHCP des Controllers (VLANs ohne eigenen DHCP)
--   art = abandon   DHCP verwirft eine Adresse (antwortete schon auf Ping)
-- Zeit = Uhr des Controllers (Ortszeit) auf die Sekunde.
-- Wie alle Schema-Dateien mehrfach ausführbar; --init-db führt sie mit aus.

USE [__DB__];
GO

-- Stage ohne Typen: freebcp lädt Text, das INSERT wandelt um.
IF OBJECT_ID('__SCHEMA__.network_wlan_ereignis_stage', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_wlan_ereignis_stage (
    am        NVARCHAR(19)  NULL,
    art       NVARCHAR(16)  NULL,
    mac       NVARCHAR(20)  NULL,
    rssi      NVARCHAR(8)   NULL,
    schwelle  NVARCHAR(8)   NULL,
    ap_mac    NVARCHAR(20)  NULL,
    ap_name   NVARCHAR(160) NULL,
    ap_ip     NVARCHAR(45)  NULL,
    ip        NVARCHAR(45)  NULL,
    vlan      NVARCHAR(8)   NULL,
    name      NVARCHAR(160) NULL,
    kategorie NVARCHAR(80)  NULL,
    stufe     NVARCHAR(20)  NULL,
    text      NVARCHAR(400) NULL
);
GO

IF OBJECT_ID('__SCHEMA__.network_wlan_ereignis', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_wlan_ereignis (
    id        BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    am        DATETIME2(0)  NOT NULL,
    art       NVARCHAR(16)  NOT NULL,
    mac       NVARCHAR(20)  NULL,
    rssi      SMALLINT      NULL,
    schwelle  SMALLINT      NULL,
    ap_mac    NVARCHAR(20)  NULL,
    ap_name   NVARCHAR(160) NULL,
    ap_ip     NVARCHAR(45)  NULL,
    ip        NVARCHAR(45)  NULL,
    vlan      SMALLINT      NULL,
    name      NVARCHAR(160) NULL,
    kategorie NVARCHAR(80)  NULL,
    stufe     NVARCHAR(20)  NULL,
    text      NVARCHAR(400) NULL
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_network_wlan_ereignis_am'
                 AND object_id = OBJECT_ID('__SCHEMA__.network_wlan_ereignis'))
CREATE NONCLUSTERED INDEX IX_network_wlan_ereignis_am
    ON __SCHEMA__.network_wlan_ereignis (am, art, mac);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_network_wlan_ereignis_mac'
                 AND object_id = OBJECT_ID('__SCHEMA__.network_wlan_ereignis'))
CREATE NONCLUSTERED INDEX IX_network_wlan_ereignis_mac
    ON __SCHEMA__.network_wlan_ereignis (mac, am);
GO
