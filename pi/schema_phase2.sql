-- Phase 2: Tabellen für Infrastruktur-Knoten, Ports und Topologie-Kanten.
-- Platzhalter __DB__/__SCHEMA__ ersetzt der Collector beim Aufruf mit
-- --init-db aus der netmon.conf; das Script ist mehrfach ausführbar
-- (legt nur an, was fehlt).

USE [__DB__];
GO

-- Die abfragbare Infrastruktur: Switches, APs, Controller, Firewall.
IF OBJECT_ID('__SCHEMA__.network_nodes', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_nodes (
    id        INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    matchKey  NVARCHAR(64)  NOT NULL,          -- Chassis-MAC, sonst ip:<ip>
    art       NVARCHAR(20)  NOT NULL,          -- switch | ap | controller | firewall
    name      NVARCHAR(160) NULL,
    ip        NVARCHAR(45)  NULL,
    modell    NVARCHAR(255) NULL,
    firmware  NVARCHAR(80)  NULL,
    standort  NVARCHAR(255) NULL,
    status    NVARCHAR(20)  NOT NULL,          -- aktiv | entdeckt | stumm
    firstSeen DATETIME2     NOT NULL DEFAULT SYSDATETIME(),
    lastSeen  DATETIME2     NULL,
    CONSTRAINT UQ_network_nodes_matchKey UNIQUE (matchKey)
);
GO

-- Je Node und Port eine Zeile; Raten (inBps/outBps) berechnet der Collector
-- aus der Differenz zweier Läufe.
IF OBJECT_ID('__SCHEMA__.network_ports', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_ports (
    id           INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    node_id      INT           NOT NULL,
    ifIndex      INT           NOT NULL,
    name         NVARCHAR(160) NULL,
    operStatus   NVARCHAR(20)  NULL,           -- up | down | ...
    adminStatus  NVARCHAR(20)  NULL,
    speedMbit    INT           NULL,
    inOctets     BIGINT        NULL,
    outOctets    BIGINT        NULL,
    zaehlerStand DATETIME2     NULL,           -- Zeitstempel der Zähler
    inBps        BIGINT        NULL,
    outBps       BIGINT        NULL,
    CONSTRAINT UQ_network_ports UNIQUE (node_id, ifIndex),
    CONSTRAINT FK_network_ports_node FOREIGN KEY (node_id)
        REFERENCES __SCHEMA__.network_nodes (id)
);
GO

-- Kanten der Karte (aus LLDP). Nachbarn ohne eigenen Node-Eintrag (z. B.
-- LLDP-sprechende PCs hinter unmanaged Verteilern) landen in zu_fremd_*.
IF OBJECT_ID('__SCHEMA__.network_links', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_links (
    id           INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    von_node_id  INT           NOT NULL,
    von_port     NVARCHAR(160) NULL,
    zu_node_id   INT           NULL,
    zu_port      NVARCHAR(160) NULL,
    zu_fremd_mac NVARCHAR(20)  NULL,
    zu_fremd_name NVARCHAR(160) NULL,
    CONSTRAINT FK_network_links_von FOREIGN KEY (von_node_id)
        REFERENCES __SCHEMA__.network_nodes (id)
);
GO

-- Staging-Zwillinge für den freebcp-Upload (Muster netscan): bewusst alles
-- NVARCHAR, konvertiert wird erst im MERGE (TRY_CONVERT/NULLIF wegen der
-- ODBC-/freebcp-Leerstring-Falle).
IF OBJECT_ID('__SCHEMA__.network_nodes_stage', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_nodes_stage (
    matchKey NVARCHAR(64)  NULL,
    art      NVARCHAR(20)  NULL,
    name     NVARCHAR(160) NULL,
    ip       NVARCHAR(45)  NULL,
    modell   NVARCHAR(255) NULL,
    firmware NVARCHAR(80)  NULL,
    standort NVARCHAR(255) NULL,
    status   NVARCHAR(20)  NULL,
    seen     NVARCHAR(1)   NULL                -- 1 = in diesem Lauf gesehen
);
GO

IF OBJECT_ID('__SCHEMA__.network_ports_stage', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_ports_stage (
    node_matchKey NVARCHAR(64)  NULL,
    ifIndex       NVARCHAR(20)  NULL,
    name          NVARCHAR(160) NULL,
    operStatus    NVARCHAR(20)  NULL,
    adminStatus   NVARCHAR(20)  NULL,
    speedMbit     NVARCHAR(20)  NULL,
    inOctets      NVARCHAR(30)  NULL,
    outOctets     NVARCHAR(30)  NULL,
    zaehlerStand  NVARCHAR(30)  NULL,
    inBps         NVARCHAR(30)  NULL,
    outBps        NVARCHAR(30)  NULL
);
GO

IF OBJECT_ID('__SCHEMA__.network_links_stage', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_links_stage (
    von_matchKey  NVARCHAR(64)  NULL,
    von_port      NVARCHAR(160) NULL,
    zu_matchKey   NVARCHAR(64)  NULL,
    zu_port       NVARCHAR(160) NULL,
    zu_fremd_mac  NVARCHAR(20)  NULL,
    zu_fremd_name NVARCHAR(160) NULL
);
GO
