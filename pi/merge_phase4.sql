-- Phase 4: ARP-Staging -> Anreicherung von network_devices. Läuft nach
-- merge_phase2.sql und bewusst VOR merge_phase3.sql, damit der FDB-Join
-- die frisch nachgetragenen MACs schon nutzen kann.
--
-- MAC und Hostname nur FÜLLEN, nie überschreiben: Handpflege geht vor; die
-- ARP-Tabelle ergänzt, was fehlt — vor allem MACs der gerouteten Netze und
-- DNS-Namen vom Domaincontroller. Dazu (unten) die Lebenszeichen: lastSeen
-- und die aktuelle IP.

USE [__DB__];
GO

UPDATE d SET d.mac = s.mac
FROM __SCHEMA__.network_devices d
JOIN __SCHEMA__.network_arp_stage s ON s.ip = d.ip
WHERE NULLIF(s.mac, '') IS NOT NULL
  AND NULLIF(d.mac, '') IS NULL;
GO

UPDATE d SET d.hostname = s.hostname
FROM __SCHEMA__.network_devices d
JOIN __SCHEMA__.network_arp_stage s ON s.ip = d.ip
WHERE NULLIF(s.hostname, '') IS NOT NULL
  AND NULLIF(d.hostname, '') IS NULL;
GO

-- Lebenszeichen: Wer in der ARP-Tabelle der Firewall oder beim WLAN-Controller
-- steht, war in den letzten Minuten aktiv. Das ersetzt den früheren nmap-Scan
-- (netscan.sh), der lastSeen fortschrieb – ohne ihn stünde das Inventar auf
-- Dauer „offline". Wie beim Scan: Schlüssel ist die MAC (matchKey groß
-- geschrieben), die IP zieht mit, wenn das Gerät eine neue bekommen hat.
--
-- Nur Netze, die das Inventar schon kennt (Spalte segment) – so landen weder
-- WAN-Nachbarn noch fremde Transfernetze in der Geräteliste. Segmente sind /24.
IF OBJECT_ID('tempdb..#gesehen') IS NOT NULL DROP TABLE #gesehen;

WITH roh AS (
    SELECT UPPER(LTRIM(RTRIM(s.mac))) AS mac,
           LTRIM(RTRIM(s.ip)) AS ip,
           NULLIF(LTRIM(RTRIM(s.hostname)), '') AS hostname,
           PARSENAME(LTRIM(RTRIM(s.ip)), 4) + '.' + PARSENAME(LTRIM(RTRIM(s.ip)), 3) + '.'
               + PARSENAME(LTRIM(RTRIM(s.ip)), 2) + '.0/24' AS segment
    FROM __SCHEMA__.network_arp_stage s
    WHERE NULLIF(s.mac, '') IS NOT NULL
      AND NULLIF(s.ip, '') IS NOT NULL
      AND PARSENAME(LTRIM(RTRIM(s.ip)), 4) IS NOT NULL
),
eindeutig AS (
    SELECT *, ROW_NUMBER() OVER (PARTITION BY mac ORDER BY CASE WHEN hostname IS NULL THEN 1 ELSE 0 END) AS rn
    FROM roh
    WHERE segment IN (SELECT DISTINCT segment FROM __SCHEMA__.network_devices WHERE segment IS NOT NULL)
)
SELECT mac, ip, hostname, segment INTO #gesehen FROM eindeutig WHERE rn = 1;

UPDATE d SET
    d.ip       = g.ip,
    d.segment  = g.segment,
    d.mac      = COALESCE(NULLIF(d.mac, ''), g.mac),
    d.hostname = COALESCE(NULLIF(d.hostname, ''), g.hostname),
    d.lastSeen = SYSDATETIME(),
    d.isOnline = 1
FROM __SCHEMA__.network_devices d
JOIN #gesehen g ON g.mac = UPPER(COALESCE(NULLIF(d.mac, ''), d.matchKey));

INSERT INTO __SCHEMA__.network_devices (matchKey, mac, ip, segment, hostname, vendor, firstSeen, lastSeen, isOnline)
SELECT g.mac, g.mac, g.ip, g.segment, g.hostname, NULL, SYSDATETIME(), SYSDATETIME(), 1
FROM #gesehen g
WHERE NOT EXISTS (
    SELECT 1 FROM __SCHEMA__.network_devices d
    WHERE UPPER(d.matchKey) = g.mac OR UPPER(d.mac) = g.mac
);

UPDATE __SCHEMA__.network_devices SET isOnline = 0
WHERE isOnline = 1 AND lastSeen < DATEADD(minute, -15, SYSDATETIME());

DROP TABLE #gesehen;
GO

TRUNCATE TABLE __SCHEMA__.network_arp_stage;
GO
