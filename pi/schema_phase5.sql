-- Phase 5: Traffic-Historie. Je Lauf und aktivem Port eine Zeile mit den
-- aktuellen Raten (bit/s); Aufbewahrung ~30 Tage, räumt merge_phase5.sql
-- bei jedem Lauf selbst ab. Mehrfach ausführbar.
-- Platzhalter __DB__/__SCHEMA__ ersetzt der Collector (--init-db).

USE [__DB__];
GO

-- Bewusst OHNE Fremdschlüssel auf network_ports: das Aufräumen verwaister
-- Nodes (merge_phase2) löscht Ports direkt; verwaiste Statistik-Zeilen
-- entsorgt merge_phase5 selbst.
IF OBJECT_ID('__SCHEMA__.network_port_stats', 'U') IS NULL
CREATE TABLE __SCHEMA__.network_port_stats (
    id         BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    port_id    INT       NOT NULL,
    erfasst_am DATETIME2 NOT NULL,
    inBps      BIGINT    NULL,
    outBps     BIGINT    NULL
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_network_port_stats_port_zeit'
                 AND object_id = OBJECT_ID('__SCHEMA__.network_port_stats'))
CREATE NONCLUSTERED INDEX IX_network_port_stats_port_zeit
    ON __SCHEMA__.network_port_stats (port_id, erfasst_am);
GO
