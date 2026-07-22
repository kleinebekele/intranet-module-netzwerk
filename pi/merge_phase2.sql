-- Phase 2: Staging -> Zieltabellen. Wird nach jedem freebcp-Upload vom
-- Collector über tsql ausgeführt (Platzhalter __DB__/__SCHEMA__ ersetzt er
-- vorher aus der netmon.conf).
--
-- Grundregeln (siehe ODBC-/freebcp-Leerstring-Falle):
--  * freebcp liefert Leerfelder als '' — deshalb überall NULLIF(...,'').
--  * Vorhandene Werte werden nie durch Leeres überschrieben (COALESCE).
--  * Kein TRY_CONVERT — der Linear-Server ist zu alt dafür (Msg 195).
--    Klassisches CONVERT reicht: die Werte schreibt der Collector selbst
--    und liefert immer saubere Zahlen bzw. Leerstrings.

USE [__DB__];
GO

-- ---------------------------------------------------------------- Nodes ----
MERGE __SCHEMA__.network_nodes AS ziel
USING (
    SELECT matchKey, art, name, ip, modell, firmware, standort, status, seen
    FROM __SCHEMA__.network_nodes_stage
    WHERE NULLIF(matchKey, '') IS NOT NULL
) AS quelle
ON ziel.matchKey = quelle.matchKey
WHEN MATCHED THEN UPDATE SET
    art      = COALESCE(NULLIF(quelle.art, ''),      ziel.art),
    name     = COALESCE(NULLIF(quelle.name, ''),     ziel.name),
    ip       = COALESCE(NULLIF(quelle.ip, ''),       ziel.ip),
    modell   = COALESCE(NULLIF(quelle.modell, ''),   ziel.modell),
    firmware = COALESCE(NULLIF(quelle.firmware, ''), ziel.firmware),
    standort = COALESCE(NULLIF(quelle.standort, ''), ziel.standort),
    status   = COALESCE(NULLIF(quelle.status, ''),   ziel.status),
    lastSeen = CASE WHEN quelle.seen = '1' THEN SYSDATETIME() ELSE ziel.lastSeen END
WHEN NOT MATCHED THEN INSERT
    (matchKey, art, name, ip, modell, firmware, standort, status, firstSeen, lastSeen)
    VALUES (
        quelle.matchKey,
        COALESCE(NULLIF(quelle.art, ''), 'switch'),
        NULLIF(quelle.name, ''),
        NULLIF(quelle.ip, ''),
        NULLIF(quelle.modell, ''),
        NULLIF(quelle.firmware, ''),
        NULLIF(quelle.standort, ''),
        COALESCE(NULLIF(quelle.status, ''), 'entdeckt'),
        SYSDATETIME(),
        CASE WHEN quelle.seen = '1' THEN SYSDATETIME() END
    );
GO

-- ---------------------------------------------------------------- Ports ----
MERGE __SCHEMA__.network_ports AS ziel
USING (
    SELECT
        n.id AS node_id,
        CONVERT(int, NULLIF(s.ifIndex, ''))                 AS ifIndex,
        NULLIF(s.name, '')                                  AS name,
        NULLIF(s.operStatus, '')                            AS operStatus,
        NULLIF(s.adminStatus, '')                           AS adminStatus,
        CONVERT(int,    NULLIF(s.speedMbit, ''))            AS speedMbit,
        CONVERT(bigint, NULLIF(s.inOctets, ''))             AS inOctets,
        CONVERT(bigint, NULLIF(s.outOctets, ''))            AS outOctets,
        CONVERT(datetime2, NULLIF(s.zaehlerStand, ''), 120) AS zaehlerStand,
        CONVERT(bigint, NULLIF(s.inBps, ''))                AS inBps,
        CONVERT(bigint, NULLIF(s.outBps, ''))               AS outBps
    FROM __SCHEMA__.network_ports_stage s
    JOIN __SCHEMA__.network_nodes n ON n.matchKey = s.node_matchKey
    WHERE NULLIF(s.ifIndex, '') IS NOT NULL
) AS quelle
ON ziel.node_id = quelle.node_id AND ziel.ifIndex = quelle.ifIndex
WHEN MATCHED THEN UPDATE SET
    name         = COALESCE(quelle.name, ziel.name),
    operStatus   = quelle.operStatus,
    adminStatus  = quelle.adminStatus,
    speedMbit    = quelle.speedMbit,
    inOctets     = quelle.inOctets,
    outOctets    = quelle.outOctets,
    zaehlerStand = quelle.zaehlerStand,
    inBps        = quelle.inBps,
    outBps       = quelle.outBps
WHEN NOT MATCHED THEN INSERT
    (node_id, ifIndex, name, operStatus, adminStatus, speedMbit,
     inOctets, outOctets, zaehlerStand, inBps, outBps)
    VALUES (quelle.node_id, quelle.ifIndex, quelle.name, quelle.operStatus,
            quelle.adminStatus, quelle.speedMbit, quelle.inOctets,
            quelle.outOctets, quelle.zaehlerStand, quelle.inBps, quelle.outBps);
GO

-- ---------------------------------------------------------------- Links ----
-- Kanten werden je abgefragtem Node komplett ersetzt (Momentaufnahme der
-- Topologie). Nodes, die in diesem Lauf nicht antworteten, behalten ihre
-- zuletzt bekannten Kanten.
DELETE l
FROM __SCHEMA__.network_links l
WHERE l.von_node_id IN (
    SELECT n.id
    FROM __SCHEMA__.network_nodes n
    JOIN __SCHEMA__.network_nodes_stage s ON s.matchKey = n.matchKey
    WHERE s.status = 'aktiv' AND s.seen = '1'
);
GO

INSERT INTO __SCHEMA__.network_links
    (von_node_id, von_port, zu_node_id, zu_port, zu_fremd_mac, zu_fremd_name)
SELECT
    n1.id,
    NULLIF(s.von_port, ''),
    n2.id,
    NULLIF(s.zu_port, ''),
    NULLIF(s.zu_fremd_mac, ''),
    NULLIF(s.zu_fremd_name, '')
FROM __SCHEMA__.network_links_stage s
JOIN __SCHEMA__.network_nodes n1 ON n1.matchKey = s.von_matchKey
LEFT JOIN __SCHEMA__.network_nodes n2 ON n2.matchKey = NULLIF(s.zu_matchKey, '');
GO

-- Staging leeren — der nächste Lauf beginnt sauber.
TRUNCATE TABLE __SCHEMA__.network_nodes_stage;
TRUNCATE TABLE __SCHEMA__.network_ports_stage;
TRUNCATE TABLE __SCHEMA__.network_links_stage;
GO
