-- VLAN-Ausbau (2026-07-28): tagged/untagged-Mitgliedschaften je Port aus der
-- Q-BRIDGE-MIB (dot1qVlanStaticTable + dot1qPvid). Wie alle Schema-Dateien
-- mehrfach ausführbar; --init-db führt sie mit aus.

USE [__DB__];
GO

IF COL_LENGTH('__SCHEMA__.network_ports', 'pvid') IS NULL
    ALTER TABLE __SCHEMA__.network_ports ADD pvid INT NULL;
GO
IF COL_LENGTH('__SCHEMA__.network_ports', 'vlanUntagged') IS NULL
    ALTER TABLE __SCHEMA__.network_ports ADD vlanUntagged NVARCHAR(400) NULL;
GO
IF COL_LENGTH('__SCHEMA__.network_ports', 'vlanTagged') IS NULL
    ALTER TABLE __SCHEMA__.network_ports ADD vlanTagged NVARCHAR(400) NULL;
GO

-- Stage-Zwilling: neue Spalten ANS ENDE — freebcp lädt positionsweise, die
-- CSV-Spaltenfolge des Collectors endet auf ...|pvid|vlanUntagged|vlanTagged.
IF COL_LENGTH('__SCHEMA__.network_ports_stage', 'pvid') IS NULL
    ALTER TABLE __SCHEMA__.network_ports_stage ADD pvid NVARCHAR(20) NULL;
GO
IF COL_LENGTH('__SCHEMA__.network_ports_stage', 'vlanUntagged') IS NULL
    ALTER TABLE __SCHEMA__.network_ports_stage ADD vlanUntagged NVARCHAR(400) NULL;
GO
IF COL_LENGTH('__SCHEMA__.network_ports_stage', 'vlanTagged') IS NULL
    ALTER TABLE __SCHEMA__.network_ports_stage ADD vlanTagged NVARCHAR(400) NULL;
GO
