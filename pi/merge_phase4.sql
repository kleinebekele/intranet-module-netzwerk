-- Phase 4: ARP-Staging -> Anreicherung von network_devices. Läuft nach
-- merge_phase2.sql und bewusst VOR merge_phase3.sql, damit der FDB-Join
-- die frisch nachgetragenen MACs schon nutzen kann.
--
-- Nur FÜLLEN, nie überschreiben: nmap (lokales Netz) und Handpflege gehen
-- vor; die ARP-Tabelle ergänzt, was fehlt — vor allem MACs der gerouteten
-- Netze und DNS-Namen vom Domaincontroller.

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

TRUNCATE TABLE __SCHEMA__.network_arp_stage;
GO
