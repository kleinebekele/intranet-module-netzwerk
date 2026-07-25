-- Phase 4: OPNsense-ARP-Anreicherung. Staging für MAC<->IP über alle Netze
-- (+ per DNS-Reverse ermittelter Name). Mehrfach ausführbar.
-- Platzhalter __DB__/__SCHEMA__ ersetzt der Collector (--init-db).

USE [__DB__];
GO

IF OBJECT_ID('__SCHEMA__.network_arp_stage', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_arp_stage (
    mac      NVARCHAR(20)  NULL,
    ip       NVARCHAR(45)  NULL,
    hostname NVARCHAR(160) NULL
);
GO
