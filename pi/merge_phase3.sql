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

-- Schnappschuss der eingebuchten Clients (Grundlage für den Andrang-Alarm
-- des Moduls): je Lauf komplett ersetzt, doppelte MACs auf eine Zeile.
DELETE FROM __SCHEMA__.network_wlan_clients;
INSERT INTO __SCHEMA__.network_wlan_clients (mac, ap_ip, ap_name, ssid, gesehen_am)
SELECT LOWER(s.mac), MAX(NULLIF(s.ap_ip, '')), MAX(NULLIF(s.ap_name, '')),
       MAX(NULLIF(s.ssid, '')), SYSDATETIME()
FROM __SCHEMA__.network_wlan_stage s
WHERE NULLIF(s.mac, '') IS NOT NULL
GROUP BY LOWER(s.mac);
GO

TRUNCATE TABLE __SCHEMA__.network_fdb_stage;
TRUNCATE TABLE __SCHEMA__.network_wlan_stage;
GO
