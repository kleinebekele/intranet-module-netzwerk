-- Phase 6: WLAN-Auswertung (2026-10-09). Client-IP im WLAN-Schnappschuss
-- (für „ohne Adresse" = 169.254.x.x), dazu zwei Verläufe: AP-Wechsel je
-- Client und Zeiträume, in denen ein Client ohne Adresse eingebucht war.
-- Wie alle Schema-Dateien mehrfach ausführbar; --init-db führt sie mit aus.

USE [__DB__];
GO

-- Stage: neue Spalte ANS ENDE — freebcp lädt positionsweise, die CSV des
-- Collectors endet auf ...|ssid|ip.
IF COL_LENGTH('__SCHEMA__.network_wlan_stage', 'ip') IS NULL
    ALTER TABLE __SCHEMA__.network_wlan_stage ADD ip NVARCHAR(45) NULL;
GO
IF COL_LENGTH('__SCHEMA__.network_wlan_clients', 'ip') IS NULL
    ALTER TABLE __SCHEMA__.network_wlan_clients ADD ip NVARCHAR(45) NULL;
GO

-- Ein Client ist zwischen zwei Läufen an einem ANDEREN AP aufgetaucht.
-- Der Collector sieht nur Momentaufnahmen (alle 5 Minuten): Sprünge dazwischen
-- fehlen, die Zahl ist also eine Untergrenze.
IF OBJECT_ID('__SCHEMA__.network_wlan_wechsel', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_wlan_wechsel (
    id           BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    mac          NVARCHAR(20)  NOT NULL,
    ssid         NVARCHAR(64)  NULL,
    von_ap_ip    NVARCHAR(45)  NULL,
    von_ap_name  NVARCHAR(160) NULL,
    nach_ap_ip   NVARCHAR(45)  NULL,
    nach_ap_name NVARCHAR(160) NULL,
    am           DATETIME2(0)  NOT NULL
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_network_wlan_wechsel_am'
                 AND object_id = OBJECT_ID('__SCHEMA__.network_wlan_wechsel'))
CREATE NONCLUSTERED INDEX IX_network_wlan_wechsel_am
    ON __SCHEMA__.network_wlan_wechsel (am, mac);
GO

-- Eingebucht, aber ohne DHCP-Adresse (169.254.x.x): eine Zeile je
-- ununterbrochenem Zeitraum, zuletzt wird je Lauf fortgeschrieben.
IF OBJECT_ID('__SCHEMA__.network_wlan_ohne_ip', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_wlan_ohne_ip (
    id       BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    mac      NVARCHAR(20)  NOT NULL,
    ip       NVARCHAR(45)  NULL,
    ssid     NVARCHAR(64)  NULL,
    ap_ip    NVARCHAR(45)  NULL,
    ap_name  NVARCHAR(160) NULL,
    erstmals DATETIME2(0)  NOT NULL,
    zuletzt  DATETIME2(0)  NOT NULL
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_network_wlan_ohne_ip_zuletzt'
                 AND object_id = OBJECT_ID('__SCHEMA__.network_wlan_ohne_ip'))
CREATE NONCLUSTERED INDEX IX_network_wlan_ohne_ip_zuletzt
    ON __SCHEMA__.network_wlan_ohne_ip (zuletzt, mac);
GO
