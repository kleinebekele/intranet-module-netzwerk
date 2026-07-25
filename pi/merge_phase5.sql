-- Phase 5: Verlaufsdaten aus der Port-Stage übernehmen. Läuft als LETZTES
-- MERGE-Script des Laufs (braucht die von merge_phase2 stehen gelassene
-- network_ports_stage) und übernimmt danach das Aufräumen aller Stages.

USE [__DB__];
GO

-- Je aktivem Port mit berechneter Rate eine Verlaufszeile. Der Zeitstempel
-- ist der Zählerstand des Collectors (ein Zeitpunkt je Lauf).
INSERT INTO __SCHEMA__.network_port_stats (port_id, erfasst_am, inBps, outBps)
SELECT
    p.id,
    CONVERT(datetime2, NULLIF(s.zaehlerStand, ''), 120),
    CONVERT(bigint, NULLIF(s.inBps, '')),
    CONVERT(bigint, NULLIF(s.outBps, ''))
FROM __SCHEMA__.network_ports_stage s
JOIN __SCHEMA__.network_nodes n ON n.matchKey = s.node_matchKey
JOIN __SCHEMA__.network_ports p
    ON p.node_id = n.id AND p.ifIndex = CONVERT(int, NULLIF(s.ifIndex, ''))
WHERE s.operStatus = 'up'
  AND NULLIF(s.zaehlerStand, '') IS NOT NULL
  AND (NULLIF(s.inBps, '') IS NOT NULL OR NULLIF(s.outBps, '') IS NOT NULL);
GO

-- Aufbewahrung: 30 Tage, danach weg.
DELETE FROM __SCHEMA__.network_port_stats
WHERE erfasst_am < DATEADD(day, -30, SYSDATETIME());
GO

-- Verlaufszeilen verschwundener Ports (Aufräumen verwaister Nodes).
DELETE s
FROM __SCHEMA__.network_port_stats s
WHERE NOT EXISTS (SELECT 1 FROM __SCHEMA__.network_ports p WHERE p.id = s.port_id);
GO

-- Staging leeren — der nächste Lauf beginnt sauber.
TRUNCATE TABLE __SCHEMA__.network_nodes_stage;
TRUNCATE TABLE __SCHEMA__.network_ports_stage;
TRUNCATE TABLE __SCHEMA__.network_links_stage;
GO
