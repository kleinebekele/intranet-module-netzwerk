-- Phase 3: FDB-Staging -> Verortung in network_devices. Läuft nach jedem
-- Sammellauf im Anschluss an merge_phase2.sql.
--
-- Ein Gerät, das gerade nicht in der FDB steht (aus, im Standby), BEHÄLT
-- seine letzte Zuordnung — zugeordnet_am sagt, von wann sie stammt. Nur eine
-- NEUE Beobachtung überschreibt. MAC-Vergleich per LOWER: der Collector
-- schreibt klein, netscan/nmap groß.

USE [__DB__];
GO

UPDATE d SET
    d.node_id       = n.id,
    d.port_name     = s.port_name,
    d.verbunden_via = 'lan',
    d.ssid          = NULL,
    d.zugeordnet_am = SYSDATETIME()
FROM __SCHEMA__.network_devices d
JOIN __SCHEMA__.network_fdb_stage s
    ON LOWER(s.mac) = LOWER(d.mac)
JOIN __SCHEMA__.network_nodes n
    ON n.matchKey = s.node_matchKey
WHERE NULLIF(s.mac, '') IS NOT NULL;
GO

TRUNCATE TABLE __SCHEMA__.network_fdb_stage;
GO
