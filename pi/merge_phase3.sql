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

-- WLAN gewinnt gegen die FDB: läuft bewusst NACH dem LAN-Update. Der AP
-- wird über seine IP gefunden (die Funk-MACs des WC7500 sind andere als
-- die LAN-MAC, unter der der AP als Node geführt wird).
UPDATE d SET
    d.node_id       = n.id,
    d.port_name     = NULL,
    d.verbunden_via = 'wlan',
    d.ssid          = NULLIF(s.ssid, ''),
    d.zugeordnet_am = SYSDATETIME()
FROM __SCHEMA__.network_devices d
JOIN __SCHEMA__.network_wlan_stage s
    ON LOWER(s.mac) = LOWER(d.mac)
JOIN __SCHEMA__.network_nodes n
    ON n.art = 'ap' AND n.ip = NULLIF(s.ap_ip, '')
WHERE NULLIF(s.mac, '') IS NOT NULL;
GO

-- Phase 6, AP-Wechsel: Steht ein Client jetzt an einem anderen AP als im
-- letzten Schnappschuss? Nur gegen einen frischen Schnappschuss vergleichen —
-- nach einer Collector-Pause wäre der „Wechsel" Stunden alt.
INSERT INTO __SCHEMA__.network_wlan_wechsel (mac, ssid, von_ap_ip, von_ap_name, nach_ap_ip, nach_ap_name, am)
SELECT n.mac, n.ssid, a.ap_ip, a.ap_name, n.ap_ip, n.ap_name, SYSDATETIME()
FROM (
    SELECT LOWER(s.mac) AS mac, MAX(NULLIF(s.ap_ip, '')) AS ap_ip,
           MAX(NULLIF(s.ap_name, '')) AS ap_name, MAX(NULLIF(s.ssid, '')) AS ssid
    FROM __SCHEMA__.network_wlan_stage s
    WHERE NULLIF(s.mac, '') IS NOT NULL
    GROUP BY LOWER(s.mac)
) n
JOIN __SCHEMA__.network_wlan_clients a ON a.mac = n.mac
WHERE a.ap_ip IS NOT NULL AND n.ap_ip IS NOT NULL AND a.ap_ip <> n.ap_ip
  AND a.gesehen_am >= DATEADD(minute, -15, SYSDATETIME());
GO

-- Phase 6, ohne Adresse: eingebuchte Clients mit 169.254.x.x. Ein offener
-- Zeitraum (zuletzt vor höchstens 15 Minuten) wird fortgeschrieben, sonst
-- beginnt ein neuer.
UPDATE o SET
    o.zuletzt = SYSDATETIME(),
    o.ip      = n.ip,
    o.ssid    = n.ssid,
    o.ap_ip   = n.ap_ip,
    o.ap_name = n.ap_name
FROM __SCHEMA__.network_wlan_ohne_ip o
JOIN (
    SELECT LOWER(s.mac) AS mac, MAX(NULLIF(s.ip, '')) AS ip, MAX(NULLIF(s.ap_ip, '')) AS ap_ip,
           MAX(NULLIF(s.ap_name, '')) AS ap_name, MAX(NULLIF(s.ssid, '')) AS ssid
    FROM __SCHEMA__.network_wlan_stage s
    WHERE NULLIF(s.mac, '') IS NOT NULL AND s.ip LIKE '169.254.%'
    GROUP BY LOWER(s.mac)
) n ON n.mac = o.mac
WHERE o.zuletzt >= DATEADD(minute, -15, SYSDATETIME());

INSERT INTO __SCHEMA__.network_wlan_ohne_ip (mac, ip, ssid, ap_ip, ap_name, erstmals, zuletzt)
SELECT n.mac, n.ip, n.ssid, n.ap_ip, n.ap_name, SYSDATETIME(), SYSDATETIME()
FROM (
    SELECT LOWER(s.mac) AS mac, MAX(NULLIF(s.ip, '')) AS ip, MAX(NULLIF(s.ap_ip, '')) AS ap_ip,
           MAX(NULLIF(s.ap_name, '')) AS ap_name, MAX(NULLIF(s.ssid, '')) AS ssid
    FROM __SCHEMA__.network_wlan_stage s
    WHERE NULLIF(s.mac, '') IS NOT NULL AND s.ip LIKE '169.254.%'
    GROUP BY LOWER(s.mac)
) n
WHERE NOT EXISTS (
    SELECT 1 FROM __SCHEMA__.network_wlan_ohne_ip o
    WHERE o.mac = n.mac AND o.zuletzt >= DATEADD(minute, -15, SYSDATETIME())
);
GO

-- Aufbewahrung beider Verläufe: 60 Tage.
DELETE FROM __SCHEMA__.network_wlan_wechsel WHERE am < DATEADD(day, -60, SYSDATETIME());
DELETE FROM __SCHEMA__.network_wlan_ohne_ip WHERE zuletzt < DATEADD(day, -60, SYSDATETIME());
GO

-- Schnappschuss der eingebuchten Clients (Grundlage für den Andrang-Alarm
-- des Moduls): je Lauf komplett ersetzt, doppelte MACs auf eine Zeile.
DELETE FROM __SCHEMA__.network_wlan_clients;
INSERT INTO __SCHEMA__.network_wlan_clients (mac, ap_ip, ap_name, ssid, ip, gesehen_am)
SELECT LOWER(s.mac), MAX(NULLIF(s.ap_ip, '')), MAX(NULLIF(s.ap_name, '')),
       MAX(NULLIF(s.ssid, '')), MAX(NULLIF(s.ip, '')), SYSDATETIME()
FROM __SCHEMA__.network_wlan_stage s
WHERE NULLIF(s.mac, '') IS NOT NULL
GROUP BY LOWER(s.mac);
GO

-- Lebenszeichen aus den Switch-Tabellen: Eine MAC in der FDB hat in den
-- letzten Minuten Verkehr gemacht (Alterung der Switches ~5 Min). Das erfasst
-- Geräte, die nie über die Firewall sprechen und deshalb in deren ARP-Tabelle
-- fehlen (Drucker, die nur mit Server und PCs im eigenen Netz reden). Die IP
-- bleibt, wie sie ist – die FDB kennt nur MACs; eine neue IP trägt Phase 4
-- nach, sobald das Gerät über die Firewall spricht.
UPDATE d SET
    d.lastSeen = SYSDATETIME(),
    d.isOnline = 1
FROM __SCHEMA__.network_devices d
WHERE NULLIF(d.mac, '') IS NOT NULL
  AND EXISTS (
      SELECT 1 FROM __SCHEMA__.network_fdb_stage s
      WHERE LOWER(s.mac) = LOWER(d.mac)
      UNION ALL
      SELECT 1 FROM __SCHEMA__.network_wlan_stage w
      WHERE LOWER(w.mac) = LOWER(d.mac)
  );
GO

TRUNCATE TABLE __SCHEMA__.network_fdb_stage;
TRUNCATE TABLE __SCHEMA__.network_wlan_stage;
GO
