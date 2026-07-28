#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
netmon_collector.py — Collector des Netzwerk-Moduls, Phase 2 (Nodes/Ports/LLDP).

Fragt die bekannten Switches per SNMPv3 ab (System-Info, Ports samt
Traffic-Zählern, LLDP-Nachbarn), entdeckt daraus rekursiv weitere Switches
und lädt die Ergebnisse per freebcp + tsql-MERGE in die MSSQL — dasselbe
Muster wie netscan.sh, das unverändert daneben weiterläuft.

Bewusst ohne fremde Python-Pakete: SNMP über die net-snmp-Kommandozeile,
Upload über FreeTDS. Konfiguration in /etc/netmon/netmon.conf (root, 600).

Aufrufe:
    netmon_collector.py            normaler Sammellauf (Cron)
    netmon_collector.py --verbose  gesprächiger Handlauf
    netmon_collector.py --init-db  legt die network_*-Tabellen einmalig an
"""

import argparse
import base64
import configparser
import json
import os
import re
import socket
import ssl
import subprocess
import sys
import urllib.parse
import urllib.request
from datetime import datetime

CONFIG_PFAD = "/etc/netmon/netmon.conf"

# ------------------------------------------------------------------ OIDs ----
OID = {
    "sysDescr":    ".1.3.6.1.2.1.1.1.0",
    "sysName":     ".1.3.6.1.2.1.1.5.0",
    "sysLocation": ".1.3.6.1.2.1.1.6.0",
    # LLDP: eigene Chassis-ID (= unser matchKey) und lokale Port-Namen
    "lldpLocChassisSubtype": ".1.0.8802.1.1.2.1.3.1.0",
    "lldpLocChassisId":      ".1.0.8802.1.1.2.1.3.2.0",
    "lldpLocPortId":         ".1.0.8802.1.1.2.1.3.7.1.3",
    # LLDP-Nachbartabelle (Spalten unter diesem Präfix, siehe LLDP_SPALTEN)
    "lldpRemTable":   ".1.0.8802.1.1.2.1.4.1.1",
    "lldpRemManAddr": ".1.0.8802.1.1.2.1.4.2.1.3",
    # Interfaces: klassische ifTable + ifXTable (64-Bit-Zähler)
    "ifTable":  ".1.3.6.1.2.1.2.2.1",
    "ifXTable": ".1.3.6.1.2.1.31.1.1.1",
    # Phase 3 — Bridge-MIB: FDB (gelernte MACs je Bridge-Port) und die
    # Übersetzung Bridge-Port -> ifIndex (das sind ZWEI Nummernräume!)
    "basePortIfIndex": ".1.3.6.1.2.1.17.1.4.1.2",
    "fdbPort":         ".1.3.6.1.2.1.17.7.1.2.2.1.2",
    # Rückfallweg für Smart Switches ohne Q-BRIDGE (GS110TPv3: "Error in
    # packet"): klassische dot1d-FDB, Index NUR die MAC (ohne fdbId/VLAN).
    "fdbPortAlt":      ".1.3.6.1.2.1.17.4.3.1.2",
    # VLANs (Q-BRIDGE): PVID je Bridge-Port, statische VLAN-Tabelle (Index =
    # VLAN-ID) und die Current-Tabelle als Rückfall (Index <TimeMark>.<VLAN>).
    "pvid":            ".1.3.6.1.2.1.17.7.1.4.5.1.1",
    "vlanStatic":      ".1.3.6.1.2.1.17.7.1.4.3.1",
    "vlanCurrent":     ".1.3.6.1.2.1.17.7.1.4.2.1",
}

# Spaltennummern innerhalb der LLDP-Nachbartabelle
LLDP_SPALTEN = {
    4: "chassisSubtype",   # 4 = MAC-Adresse, 7 = lokal vergebener String
    5: "chassisId",
    7: "portId",
    9: "sysName",
    10: "sysDesc",
    12: "capabilities",    # Bitmaske: 0x20 Bridge, 0x10 WLAN-AP, 0x08 Router
}

# Spalten der ifTable/ifXTable, die wir brauchen
IF_SPALTEN = {
    ".1.3.6.1.2.1.2.2.1.3": "typ",          # ifType (6 = Ethernet, 161 = LAG)
    ".1.3.6.1.2.1.2.2.1.7": "admin",        # 1 = up, 2 = down
    ".1.3.6.1.2.1.2.2.1.8": "oper",
    ".1.3.6.1.2.1.31.1.1.1.1": "name",      # ifName
    ".1.3.6.1.2.1.31.1.1.1.6": "inOctets",  # ifHCInOctets (64 Bit)
    ".1.3.6.1.2.1.31.1.1.1.10": "outOctets",
    ".1.3.6.1.2.1.31.1.1.1.15": "speed",    # ifHighSpeed (Mbit)
}

STATUS_TEXT = {1: "up", 2: "down", 3: "testing", 4: "unknown",
               5: "dormant", 6: "notPresent", 7: "lowerLayerDown"}

PORT_TYPEN = {6, 161}  # physisches Ethernet + Link Aggregation

# Namensschema physischer Ports bei Netgear: "0/1", gestapelt "1/0/1"
PHYS_PORT_RE = re.compile(r'^\d+/\d+(/\d+)?$')


def log(text):
    print(f"{datetime.now():%Y-%m-%d %H:%M:%S} {text}", flush=True)


class Config:
    """netmon.conf einlesen und SNMP-Argumente je Host bauen."""

    def __init__(self, pfad):
        self.ini = configparser.ConfigParser()
        if not self.ini.read(pfad):
            sys.exit(f"FEHLER: Konfiguration {pfad} fehlt oder ist unlesbar.")
        for pflicht in ("snmp", "discovery", "mssql", "collector"):
            if pflicht not in self.ini:
                sys.exit(f"FEHLER: Abschnitt [{pflicht}] fehlt in {pfad}.")

    def snmp_argumente(self, ip):
        """Kommandozeilen-Argumente für snmpget/snmpbulkwalk gegen diese IP —
        Standardwerte aus [snmp], überschrieben durch einen etwaigen
        Ausnahme-Abschnitt [snmp:IP] (z. B. der S3300 mit SHA-512/AES).

        Mit `version = 2c` + `community = …` im Ausnahme-Abschnitt wird das
        Gerät stattdessen per SNMPv2c abgefragt — für Geräte, die kein
        brauchbares v3 können (ältere Smart-Switches, WC7500). Niemals die
        Admin-Kopplung solcher Geräte nutzen!"""
        werte = dict(self.ini["snmp"])
        ausnahme = f"snmp:{ip}"
        if ausnahme in self.ini:
            werte.update(dict(self.ini[ausnahme]))
        allgemein = ["-t", werte.get("timeout", "2"),
                     "-r", werte.get("retries", "1"), "-On"]
        if werte.get("version", "3") == "2c":
            return ["-v2c", "-c", werte["community"]] + allgemein
        return [
            "-v3", "-l", "authPriv",
            "-u", werte["user"],
            "-a", werte["auth_protocol"], "-A", werte["auth_password"],
            "-x", werte["priv_protocol"], "-X", werte["priv_password"],
        ] + allgemein

    @property
    def seed_ips(self):
        return [ip.strip() for ip in self.ini["discovery"]["seed_ips"].split(",") if ip.strip()]

    @property
    def stumm_schwelle(self):
        return self.ini["discovery"].getint("stumm_nach_fehlversuchen", 3)

    @property
    def mssql(self):
        return self.ini["mssql"]

    @property
    def opnsense(self):
        """[opnsense]-Abschnitt oder None — Phase 4 ist optional."""
        return self.ini["opnsense"] if "opnsense" in self.ini else None

    @property
    def dns_server(self):
        return self.ini["dns"].get("server", "") if "dns" in self.ini else ""

    @property
    def state_dir(self):
        return self.ini["collector"].get("state_dir", "/var/lib/netmon")

    @property
    def sql_dir(self):
        return self.ini["collector"].get("sql_dir", "/usr/local/share/netmon")


# ------------------------------------------------------------- SNMP-Lauf ----
ZEILE_MIT_TYP = re.compile(r'^(\.[0-9.]+) = ([A-Za-z0-9-]+): ?(.*)$', re.S)
ZEILE_OHNE_TYP = re.compile(r'^(\.[0-9.]+) = "(.*)"$', re.S)  # leere Strings


def snmp_abfrage(kommando, argumente, ip, oids, verbose=False):
    """snmpget/snmpbulkwalk ausführen und Ausgabe in (oid, typ, wert)-Tripel
    zerlegen. Liefert None, wenn der Host nicht (korrekt) antwortet."""
    cmd = [kommando] + argumente + [ip] + oids
    lauf = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
    if lauf.returncode != 0:
        if verbose:
            log(f"  {ip}: {kommando} fehlgeschlagen ({lauf.stderr.strip().splitlines()[:1]})")
        return None

    tripel = []
    for zeile in lauf.stdout.splitlines():
        m = ZEILE_MIT_TYP.match(zeile)
        if m:
            tripel.append([m.group(1), m.group(2), m.group(3)])
            continue
        m = ZEILE_OHNE_TYP.match(zeile)
        if m:
            tripel.append([m.group(1), "STRING", f'"{m.group(2)}"'])
            continue
        # Fortsetzungszeile (umgebrochener Hex-/String-Wert) -> anhängen
        if tripel and zeile:
            tripel[-1][2] += " " + zeile.strip()
    return [tuple(t) for t in tripel]


def wert_text(typ, roh):
    """Wert als bereinigten Text (STRING ohne Anführungszeichen)."""
    roh = roh.strip()
    if typ == "STRING" and len(roh) >= 2 and roh.startswith('"') and roh.endswith('"'):
        roh = roh[1:-1]
    return roh.strip()


def wert_zahl(roh):
    m = re.search(r'-?\d+', roh)
    return int(m.group(0)) if m else None


def wert_bytes(typ, roh):
    """Oktetten eines OCTET-STRING — net-snmp zeigt sie je nach Inhalt als
    Hex-STRING ("20 00") ODER als druckbaren STRING ("(")."""
    if typ == "Hex-STRING":
        return bytes(int(h, 16) for h in wert_text(typ, roh).split() if re.fullmatch(r'[0-9A-Fa-f]{2}', h))
    return wert_text(typ, roh).encode("latin-1", "replace")


def mac_format(oktetten):
    if len(oktetten) != 6:
        return ""
    return ":".join(f"{b:02x}" for b in oktetten)


def vlan_bitmap_ports(oktetten):
    """Bridge-Portnummern aus einer 802.1Q-Portlisten-Bitmaske: Bit 0x80 des
    ersten Oktetts = Bridge-Port 1, dann fortlaufend."""
    ports = set()
    for i, byte in enumerate(oktetten):
        for bit in range(8):
            if byte & (0x80 >> bit):
                ports.add(i * 8 + bit + 1)
    return ports


def vlan_mitglieder(argumente, ip, verbose):
    """VLAN-ID -> {"egress": Bridge-Ports, "untagged": Bridge-Ports} aus der
    Q-BRIDGE. Erst die statische Tabelle (= Konfiguration, Index die VLAN-ID),
    liefert die nichts, die Current-Tabelle (Index <TimeMark>.<VLAN>) — beide
    Parser identisch, weil die VLAN-ID jeweils die LETZTE Index-Komponente
    ist. Leeres Dict bei Geräten ohne Q-BRIDGE (GS110TPv3)."""
    for basis, egress_spalte, untagged_spalte in ((OID["vlanStatic"], 2, 4),
                                                  (OID["vlanCurrent"], 4, 5)):
        vlans = {}
        for oid, typ, roh in snmp_abfrage("snmpbulkwalk", argumente, ip, [basis], verbose) or []:
            rest = oid[len(basis) + 1:].split(".")
            if len(rest) < 2 or not rest[0].isdigit() or not rest[-1].isdigit():
                continue
            spalte, vlan_id = int(rest[0]), int(rest[-1])
            if spalte not in (egress_spalte, untagged_spalte):
                continue
            eintrag = vlans.setdefault(vlan_id, {"egress": set(), "untagged": set()})
            ziel = "egress" if spalte == egress_spalte else "untagged"
            eintrag[ziel] |= vlan_bitmap_ports(wert_bytes(typ, roh))
        if any(v["egress"] for v in vlans.values()):
            return vlans
    return {}


# ------------------------------------------------------- Node abfragen ------
def node_abfragen(cfg, ip, verbose):
    """Einen Switch komplett einsammeln. None = keine Antwort."""
    argumente = cfg.snmp_argumente(ip)

    system = snmp_abfrage("snmpget", argumente, ip,
                          [OID["sysDescr"], OID["sysName"], OID["sysLocation"],
                           OID["lldpLocChassisSubtype"], OID["lldpLocChassisId"]],
                          verbose)
    if system is None:
        return None

    node = {"ip": ip, "name": "", "modell": "", "firmware": "", "standort": "",
            "matchKey": f"ip:{ip}", "ports": {}, "nachbarn": [], "lokalePorts": {}}

    for oid, typ, roh in system:
        if oid == OID["sysDescr"]:
            # Netgear-Format: "M4300-8X8F ProSAFE ..., 12.0.2.6, 1.0.0.8"
            teile = [t.strip() for t in wert_text(typ, roh).split(",")]
            node["modell"] = teile[0] if teile else ""
            node["firmware"] = teile[1] if len(teile) > 1 else ""
        elif oid == OID["sysName"]:
            node["name"] = wert_text(typ, roh)
        elif oid == OID["sysLocation"]:
            node["standort"] = wert_text(typ, roh)
        elif oid == OID["lldpLocChassisId"]:
            mac = mac_format(wert_bytes(typ, roh))
            if mac:
                node["matchKey"] = mac

    # ---- Ports: ifTable + ifXTable in zwei Walks
    interfaces = {}
    for tabelle in (OID["ifTable"], OID["ifXTable"]):
        zeilen = snmp_abfrage("snmpbulkwalk", argumente, ip, [tabelle], verbose)
        for oid, typ, roh in zeilen or []:
            praefix, _, index = oid.rpartition(".")
            feld = IF_SPALTEN.get(praefix)
            if feld is None or not index.isdigit():
                continue
            eintrag = interfaces.setdefault(int(index), {})
            if feld in ("typ", "admin", "oper", "inOctets", "outOctets", "speed"):
                eintrag[feld] = wert_zahl(roh)
            else:
                eintrag[feld] = wert_text(typ, roh)

    for index, werte in interfaces.items():
        # Physische Ports erkennt man am Namen ("0/1", gestapelt "1/0/1") —
        # NICHT am ifType: alte M4200-Firmware meldet linklose Ports als
        # "other" statt Ethernet. LAGs (bis zu 128 Platzhalter je Switch)
        # nur, wenn tatsächlich in Betrieb; CPU-/VLAN-Interfaces gar nicht.
        # Zwei Kriterien, weil keines allein überall gilt: der S3300 nennt
        # seine Ports nicht "0/1" (aber typisiert sie sauber als Ethernet).
        name = werte.get("name", "")
        ist_physisch = bool(PHYS_PORT_RE.match(name)) or werte.get("typ") == 6
        ist_lag = werte.get("typ") == 161 or name.lower().startswith("lag")
        if not ist_physisch and not (ist_lag and werte.get("oper") == 1):
            continue
        node["ports"][index] = {
            "name": werte.get("name", ""),
            "admin": STATUS_TEXT.get(werte.get("admin"), ""),
            "oper": STATUS_TEXT.get(werte.get("oper"), ""),
            "speed": werte.get("speed"),
            "inOctets": werte.get("inOctets"),
            "outOctets": werte.get("outOctets"),
        }

    # ---- Namen der lokalen Ports (für die Kanten-Beschriftung)
    for oid, typ, roh in snmp_abfrage("snmpbulkwalk", argumente, ip, [OID["lldpLocPortId"]], verbose) or []:
        nummer = oid.rpartition(".")[2]
        if nummer.isdigit():
            node["lokalePorts"][int(nummer)] = wert_text(typ, roh)

    # ---- LLDP-Nachbarn: ganze Tabelle in einem Walk, Spalten aufdröseln.
    #      Index je Zeile: <spalte>.<timeMark>.<lokalerPort>.<laufNr>
    nachbarn = {}
    for oid, typ, roh in snmp_abfrage("snmpbulkwalk", argumente, ip, [OID["lldpRemTable"]], verbose) or []:
        rest = oid[len(OID["lldpRemTable"]) + 1:].split(".")
        if len(rest) != 4:
            continue
        spalte, _, lokaler_port, lauf_nr = (int(x) for x in rest)
        feld = LLDP_SPALTEN.get(spalte)
        if feld is None:
            continue
        eintrag = nachbarn.setdefault((lokaler_port, lauf_nr), {"lokalerPort": lokaler_port})
        if feld in ("chassisId", "capabilities"):
            eintrag[feld] = wert_bytes(typ, roh)
        elif feld == "chassisSubtype":
            eintrag[feld] = wert_zahl(roh)
        else:
            eintrag[feld] = wert_text(typ, roh)

    # Management-IPs der Nachbarn stecken im Index der ManAddr-Tabelle:
    # ...<timeMark>.<lokalerPort>.<laufNr>.1.4.<a>.<b>.<c>.<d>
    for oid, _typ, _roh in snmp_abfrage("snmpbulkwalk", argumente, ip, [OID["lldpRemManAddr"]], verbose) or []:
        rest = oid[len(OID["lldpRemManAddr"]) + 1:].split(".")
        if len(rest) == 9 and rest[3] == "1" and rest[4] == "4":
            schluessel = (int(rest[1]), int(rest[2]))
            if schluessel in nachbarn:
                nachbarn[schluessel]["mgmtIp"] = ".".join(rest[5:9])

    node["nachbarn"] = list(nachbarn.values())

    # ---- FDB: welche MACs hat der Switch an welchem Port gelernt?
    #      Der Index der FDB-Tabelle nennt BRIDGE-Ports; die Übersetzung zum
    #      ifIndex liefert dot1dBasePortIfIndex. CPU-Ports (z. B. 10000 bei
    #      Netgear) fehlen in der Übersetzung und fallen so von selbst raus.
    base_zu_if = {}
    for oid, _typ, roh in snmp_abfrage("snmpbulkwalk", argumente, ip, [OID["basePortIfIndex"]], verbose) or []:
        nummer = oid.rpartition(".")[2]
        if nummer.isdigit():
            base_zu_if[int(nummer)] = wert_zahl(roh)

    fdb = {}  # ifIndex -> set der dort gelernten MACs
    fdb_zeilen = snmp_abfrage("snmpbulkwalk", argumente, ip, [OID["fdbPort"]], verbose)
    fdb_praefix, index_laenge = OID["fdbPort"], 7   # <fdbId/VLAN>.<6 MAC-Oktetten>
    if not fdb_zeilen:
        fdb_zeilen = snmp_abfrage("snmpbulkwalk", argumente, ip, [OID["fdbPortAlt"]], verbose)
        fdb_praefix, index_laenge = OID["fdbPortAlt"], 6   # nur <6 MAC-Oktetten>
    for oid, _typ, roh in fdb_zeilen or []:
        rest = oid[len(fdb_praefix) + 1:].split(".")
        if len(rest) != index_laenge:
            continue
        if_index = base_zu_if.get(wert_zahl(roh))
        if if_index is None:    # Port 0 (nicht gelernt) oder CPU-Port
            continue
        mac = ":".join(f"{int(x):02x}" for x in rest[-6:])
        fdb.setdefault(if_index, set()).add(mac)
    node["fdb"] = fdb

    # ---- VLANs: untagged/tagged Mitgliedschaften je Port. Tagged = in der
    #      Egress-Maske, aber nicht in der Untagged-Maske. Fehlt die
    #      Untagged-Maske (oder das ganze Q-BRIDGE), bleibt als bester Wert
    #      der PVID — das untagged Ingress-VLAN des Ports.
    pvid_je_if = {}
    for oid, _typ, roh in snmp_abfrage("snmpbulkwalk", argumente, ip, [OID["pvid"]], verbose) or []:
        nummer = oid.rpartition(".")[2]
        if nummer.isdigit() and base_zu_if.get(int(nummer)) is not None:
            pvid_je_if[base_zu_if[int(nummer)]] = wert_zahl(roh)

    untagged_je_if, tagged_je_if = {}, {}
    for vlan_id, m in vlan_mitglieder(argumente, ip, verbose).items():
        for basisport in m["egress"]:
            if_index = base_zu_if.get(basisport)
            if if_index is None:    # CPU-Port o. ä.
                continue
            ziel = untagged_je_if if basisport in m["untagged"] else tagged_je_if
            ziel.setdefault(if_index, set()).add(vlan_id)

    for index, port in node["ports"].items():
        untagged = untagged_je_if.get(index, set())
        pvid = pvid_je_if.get(index)
        if not untagged and pvid:
            untagged = {pvid}
        port["pvid"] = pvid
        port["vlanUntagged"] = ",".join(str(v) for v in sorted(untagged))
        port["vlanTagged"] = ",".join(str(v) for v in sorted(tagged_je_if.get(index, set())))
    return node


def nachbar_faehigkeiten(nachbar):
    """(istBridge, istAp) aus der LLDP-Capabilities-Bitmaske.

    Netgear liefert die beiden Oktetten in vertauschter Reihenfolge
    ("00 28" statt "28 00") — deshalb alle Oktetten zusammen-ODERn, dann ist
    die Byte-Reihenfolge egal (Bridge 0x20 und WLAN-AP 0x10 kollidieren in
    keiner der beiden Lesarten mit anderen Bits)."""
    oktetten = nachbar.get("capabilities")
    if oktetten is None:
        # Spalte fehlt ganz: eine mitgesendete Management-IP ist dann das
        # beste Indiz für ein Infrastruktur-Gerät.
        return bool(nachbar.get("mgmtIp")), False
    kombiniert = 0
    for byte in oktetten:
        kombiniert |= byte
    return bool(kombiniert & 0x20), bool(kombiniert & 0x10)


def nachbar_matchkey(nachbar):
    if nachbar.get("chassisSubtype") == 4:  # MAC-Adresse
        mac = mac_format(nachbar.get("chassisId") or b"")
        if mac:
            return mac
    if nachbar.get("mgmtIp"):
        return f"ip:{nachbar['mgmtIp']}"
    return ""


# -------------------------------------------------- WC7500-WLAN (Phase 3) ---
# Spalten der beiden Tabellen im Netgear-Enterprise-Baum, am echten Gerät
# ausgemessen (--wlan-erkunden, 2026-07-25; eine MIB liegt nicht vor).
# Zeilen-Index beider Tabellen: <Spalte>.6.<6 MAC-Oktetten dezimal>.
WLAN_TABELLEN = {
    "aps":     (".1.3.6.1.4.1.4526.100.8.6.3.1.1",
                {2: "ip", 8: "name", 9: "modell", 14: "standort", 17: "status"}),
    "clients": (".1.3.6.1.4.1.4526.100.8.6.4.1.1",
                {2: "ip", 4: "apName", 5: "apIp", 8: "ssid"}),
}


def wlan_tabelle(cfg, ip, name, verbose):
    """Eine WC7500-Tabelle einlesen: Liste von Dicts, je Zeile die MAC aus
    dem Index plus die interessanten Spalten."""
    basis, spalten = WLAN_TABELLEN[name]
    zeilen = snmp_abfrage("snmpbulkwalk", cfg.snmp_argumente(ip), ip, [basis], verbose)
    eintraege = {}
    for oid, typ, roh in zeilen or []:
        rest = oid[len(basis) + 1:].split(".")
        if len(rest) != 8 or rest[1] != "6" or not rest[0].isdigit():
            continue
        mac = ":".join(f"{int(x):02x}" for x in rest[2:8])
        feld = spalten.get(int(rest[0]))
        if feld is not None:
            eintraege.setdefault(mac, {"mac": mac})[feld] = wert_text(typ, roh)
    return list(eintraege.values())


# ----------------------------------------------------- OPNsense (Phase 4) ---
MAC_RE = re.compile(r'^[0-9a-f]{2}(:[0-9a-f]{2}){5}$')


def opnsense_abfragen(cfg, pfad):
    """GET gegen die OPNsense-API (Basic Auth mit Key:Secret). Selbstsignierte
    Zertifikate sind bei Firewalls der Normalfall -> keine Zertifikatsprüfung."""
    o = cfg.opnsense
    url = o["url"].rstrip("/") + pfad
    anmeldung = base64.b64encode(f"{o['key']}:{o['secret']}".encode()).decode()
    anfrage = urllib.request.Request(url, headers={"Authorization": f"Basic {anmeldung}"})
    kontext = ssl._create_unverified_context()
    with urllib.request.urlopen(anfrage, timeout=30, context=kontext) as antwort:
        return json.loads(antwort.read().decode("utf-8", "replace"))


def arp_einlesen(cfg, verbose):
    """ARP-Tabelle der Firewall: MAC<->IP über ALLE Netze. Die Switch-FDBs
    kennen nur MACs und nmap sieht MACs nur im eigenen Netz — erst mit der
    ARP-Tabelle bekommen auch Geräte in gerouteten Netzen ihre MAC (und
    darüber dann ihre Port-Zuordnung)."""
    try:
        daten = opnsense_abfragen(cfg, "/api/diagnostics/interface/get_arp")
    except Exception as fehler:
        log(f"FEHLER: OPNsense-ARP nicht lesbar: {fehler}")
        return []
    zeilen = daten.get("rows", []) if isinstance(daten, dict) else daten
    eintraege = []
    for zeile in zeilen or []:
        mac = str(zeile.get("mac", "")).lower().strip()
        ip = str(zeile.get("ip", "")).strip()
        if MAC_RE.match(mac) and mac != "00:00:00:00:00:00" and ip:
            eintraege.append({"mac": mac, "ip": ip})
    if verbose:
        log(f"  OPNsense-ARP: {len(eintraege)} verwertbare Einträge")
    return eintraege


def dns_name(ip, server):
    """Reverse-Lookup, bevorzugt gegen den konfigurierten Server (der
    Domaincontroller ist das DNS — die OPNsense macht kein DHCP)."""
    kommando = ["dig", "+short", "+time=2", "+tries=1", "-x", ip]
    if server:
        kommando.append(f"@{server}")
    try:
        lauf = subprocess.run(kommando, capture_output=True, text=True, timeout=10)
    except FileNotFoundError:       # kein dig installiert -> System-Resolver
        try:
            return socket.gethostbyaddr(ip)[0]
        except OSError:
            return ""
    except subprocess.TimeoutExpired:
        return ""
    if lauf.returncode != 0:
        return ""
    for zeile in lauf.stdout.splitlines():
        zeile = zeile.strip().rstrip(".")
        if zeile and not zeile.startswith(";"):
            return zeile
    return ""


def lokale_arp_mac(ip):
    """MAC aus der ARP-Tabelle des Pi (/proc/net/arp) — etwa die der Firewall,
    mit der der API-Aufruf eben gesprochen hat (ihre eigene MAC steht nicht
    in ihrer ARP-Tabelle)."""
    try:
        with open("/proc/net/arp", encoding="ascii", errors="replace") as f:
            next(f)
            for zeile in f:
                teile = zeile.split()
                if (len(teile) >= 4 and teile[0] == ip
                        and MAC_RE.match(teile[3].lower())
                        and teile[3] != "00:00:00:00:00:00"):
                    return teile[3].lower()
    except OSError:
        pass
    return ""


# ------------------------------------------------------------ MSSQL-Weg -----
def csv_feld(wert):
    """Ein Feld für die Pipe-CSV: None -> leer, Trennzeichen entschärfen."""
    if wert is None:
        return ""
    return str(wert).replace("|", " ").replace("\r", " ").replace("\n", " ").strip()


def csv_schreiben(pfad, zeilen):
    with open(pfad, "w", encoding="utf-8") as f:
        for zeile in zeilen:
            f.write("|".join(csv_feld(feld) for feld in zeile) + "\n")


def tsql_ausfuehren(cfg, sql, beschreibung):
    m = cfg.mssql
    umgebung = dict(os.environ, TDSPORT=m.get("port", "1433"))
    lauf = subprocess.run(
        ["tsql", "-H", m["server"], "-p", m.get("port", "1433"),
         "-U", m["user"], "-P", m["password"]],
        input=sql + "\nGO\nexit\n",
        capture_output=True, text=True, timeout=300, env=umgebung)
    fehler = [z for z in (lauf.stdout + lauf.stderr).splitlines()
              if "Msg " in z or "Error" in z]
    if lauf.returncode != 0 or fehler:
        log(f"FEHLER bei {beschreibung}:")
        for zeile in fehler[:10]:
            log(f"  {zeile}")
        return False
    return True


def freebcp_hochladen(cfg, tabelle, csv_pfad):
    m = cfg.mssql
    ziel = f"{m['database']}.{m['schema']}.{tabelle}"
    umgebung = dict(os.environ, TDSPORT=m.get("port", "1433"))
    lauf = subprocess.run(
        ["freebcp", ziel, "in", csv_pfad,
         "-S", m["server"], "-U", m["user"], "-P", m["password"],
         "-c", "-t", "|"],
        capture_output=True, text=True, timeout=300, env=umgebung)
    if lauf.returncode != 0:
        log(f"FEHLER: freebcp nach {ziel}: {lauf.stderr.strip()}")
        return False
    return True


def sql_datei(cfg, name):
    pfad = os.path.join(cfg.sql_dir, name)
    with open(pfad, encoding="utf-8") as f:
        inhalt = f.read()
    return (inhalt.replace("__DB__", cfg.mssql["database"])
                  .replace("__SCHEMA__", cfg.mssql["schema"]))


# ------------------------------------------------------------- Hauptlauf ----
def state_laden(cfg):
    pfad = os.path.join(cfg.state_dir, "state.json")
    if os.path.exists(pfad):
        with open(pfad, encoding="utf-8") as f:
            return json.load(f)
    return {"nodes": {}, "zaehler": {}}


def state_sichern(cfg, state):
    os.makedirs(cfg.state_dir, exist_ok=True)
    pfad = os.path.join(cfg.state_dir, "state.json")
    with open(pfad + ".neu", "w", encoding="utf-8") as f:
        json.dump(state, f, indent=1)
    os.replace(pfad + ".neu", pfad)


def raten_berechnen(state, match_key, ports, jetzt):
    """inBps/outBps aus der Differenz zum letzten Lauf; Zählerstände merken."""
    alt = state["zaehler"].get(match_key, {})
    neu = {}
    for index, port in ports.items():
        neu[str(index)] = {"in": port["inOctets"], "out": port["outOctets"], "ts": jetzt}
        vorher = alt.get(str(index))
        port["inBps"] = port["outBps"] = None
        if not vorher or vorher.get("ts") is None:
            continue
        dt = jetzt - vorher["ts"]
        if not 30 <= dt <= 3600:   # zu kurz/zu alt -> keine seriöse Rate
            continue
        for richtung, feld in (("in", "inBps"), ("out", "outBps")):
            a, b = vorher.get(richtung), port.get(f"{richtung}Octets")
            if a is not None and b is not None and b >= a:  # b < a = Zähler-Reset
                port[feld] = int((b - a) * 8 / dt)
    state["zaehler"][match_key] = neu


def duplikate_zusammenfuehren(nodes, state):
    """Zwei Einträge mit derselben IP sind dasselbe Gerät — passiert, wenn
    ein Switch einmal per LLDP entdeckt wurde (Chassis-MAC der Nachbarn als
    Schlüssel) und sein eigener Poll später eine ANDERE Kennung lieferte
    (ip:<ip>, oder Port- statt Chassis-MAC). Der gepollte Eintrag gewinnt;
    die übrigen Schlüssel bleiben als Aliase erhalten, damit die
    LLDP-Verweise der Nachbarn weiter auf den einen Node zeigen."""
    rang = {"aktiv": 0, "stumm": 1, "entdeckt": 2}
    nach_ip = {}
    for key, node in nodes.items():
        if node.get("ip"):
            nach_ip.setdefault(node["ip"], []).append(key)
    for ip, schluessel in nach_ip.items():
        if len(schluessel) < 2:
            continue
        schluessel.sort(key=lambda k: rang.get(nodes[k]["status"], 9))
        kanon = schluessel[0]
        for key in schluessel[1:]:
            doppel = nodes.pop(key)
            aliase = set(nodes[kanon].get("aliase", [])) | set(doppel.get("aliase", [])) | {key}
            aliase.discard(kanon)
            nodes[kanon]["aliase"] = sorted(aliase)
            for feld in ("name", "modell", "firmware", "standort"):
                if not nodes[kanon].get(feld):
                    nodes[kanon][feld] = doppel.get(feld, "")
            state["zaehler"].pop(key, None)
            log(f"Doppelten Eintrag zusammengeführt: {key} -> {kanon} ({ip})")


def sammellauf(cfg, verbose):
    state = state_laden(cfg)
    nodes = state["nodes"]
    jetzt = int(datetime.now().timestamp())
    zeitstempel = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    # Erstlauf: Seeds als aktive Switches vormerken (matchKey klärt der Poll).
    for ip in cfg.seed_ips:
        if not any(n["ip"] == ip for n in nodes.values()):
            nodes[f"ip:{ip}"] = {"ip": ip, "art": "switch", "status": "aktiv",
                                 "name": "", "modell": "", "firmware": "",
                                 "standort": "", "fehlversuche": 0}

    duplikate_zusammenfuehren(nodes, state)

    # 1) "entdeckt"-Switches: je Lauf einmal anklopfen — klappt der
    #    netmon-Zugang inzwischen, werden sie aktiv (und gleich voll gepollt).
    for key, node in nodes.items():
        if node["status"] == "entdeckt" and node["art"] == "switch" and node["ip"]:
            probe = snmp_abfrage("snmpget", cfg.snmp_argumente(node["ip"]),
                                 node["ip"], [OID["sysName"]], verbose)
            if probe is not None:
                log(f"Neuer Switch eingebunden: {node['ip']} ({node.get('name') or key})")
                node["status"] = "aktiv"
                node["fehlversuche"] = 0

    # 2) Alle aktiven/stummen Switches abfragen
    ergebnisse = {}   # matchKey -> Abfrage-Ergebnis
    gesehen = set()   # matchKeys, die in diesem Lauf lebten (Poll oder LLDP)
    for key in list(nodes.keys()):
        node = nodes[key]
        if node["art"] != "switch" or node["status"] == "entdeckt":
            continue
        daten = node_abfragen(cfg, node["ip"], verbose)
        if daten is None:
            node["fehlversuche"] = node.get("fehlversuche", 0) + 1
            if node["status"] == "aktiv" and node["fehlversuche"] >= cfg.stumm_schwelle:
                log(f"Switch antwortet nicht mehr: {node['ip']} ({node.get('name') or key}) -> stumm")
                node["status"] = "stumm"
            continue

        node.update({"status": "aktiv", "fehlversuche": 0,
                     "name": daten["name"] or node.get("name", ""),
                     "modell": daten["modell"] or node.get("modell", ""),
                     "firmware": daten["firmware"] or node.get("firmware", ""),
                     "standort": daten["standort"] or node.get("standort", "")})

        # Seed-Platzhalter (ip:...) auf die echte Chassis-MAC umziehen. Der
        # alte Schlüssel bleibt als Alias; steht unter dem neuen Schlüssel
        # schon ein (z. B. per LLDP entdeckter) Eintrag, wird er geschluckt.
        if daten["matchKey"] != key:
            neu = daten["matchKey"]
            alt = nodes.pop(key)
            aliase = set(alt.get("aliase", [])) | {key}
            if neu in nodes:
                aliase |= set(nodes[neu].get("aliase", []))
            aliase.discard(neu)
            alt["aliase"] = sorted(aliase)
            nodes[neu] = alt
            if key in state["zaehler"]:
                state["zaehler"][neu] = state["zaehler"].pop(key)
            key = neu

        raten_berechnen(state, key, daten["ports"], jetzt)
        ergebnisse[key] = daten
        gesehen.add(key)
        if verbose:
            log(f"  {node['ip']} ({daten['name']}): {len(daten['ports'])} Ports, "
                f"{len(daten['nachbarn'])} LLDP-Nachbarn")

    # 3) Discovery: LLDP-Nachbarn mit Bridge-/AP-Fähigkeit, die noch fehlen
    aliase = {a: key for key, node in nodes.items() for a in node.get("aliase", [])}
    for key, daten in ergebnisse.items():
        for nachbar in daten["nachbarn"]:
            bridge, ap = nachbar_faehigkeiten(nachbar)
            n_key = nachbar_matchkey(nachbar)
            if not n_key:
                continue
            n_key = aliase.get(n_key, n_key)
            if n_key in nodes:
                gesehen.add(n_key)
                if not nodes[n_key].get("ip") and nachbar.get("mgmtIp"):
                    nodes[n_key]["ip"] = nachbar["mgmtIp"]
                continue
            # Meldet der Nachbar eine IP, die schon einem Node gehört, ist es
            # derselbe Kasten unter anderer Kennung -> Alias statt Duplikat.
            mgmt = nachbar.get("mgmtIp")
            treffer = None
            if mgmt:
                treffer = next((k for k, n in nodes.items() if n.get("ip") == mgmt), None)
            if treffer:
                nodes[treffer]["aliase"] = sorted(set(nodes[treffer].get("aliase", [])) | {n_key})
                aliase[n_key] = treffer
                gesehen.add(treffer)
                continue
            if not (bridge or ap):
                continue  # PCs & Co. werden Kanten (zu_fremd_*), keine Nodes
            art = "ap" if ap and not bridge else "switch"
            nodes[n_key] = {"ip": nachbar.get("mgmtIp", ""), "art": art,
                            "status": "entdeckt", "name": nachbar.get("sysName", ""),
                            "modell": (nachbar.get("sysDesc", "").split(",")[0]).strip(),
                            "firmware": "", "standort": "", "fehlversuche": 0}
            gesehen.add(n_key)
            log(f"Entdeckt ({art}): {nachbar.get('sysName') or n_key} "
                f"[{nachbar.get('mgmtIp', 'IP unbekannt')}] "
                f"an {daten['name']} Port {nachbar['lokalerPort']}"
                + (" — netmon-Benutzer anlegen, dann bindet der Collector ihn selbst ein"
                   if art == "switch" else ""))

    # 3b) FDB auswerten: Gerät -> (Node, Port). Uplink-Ports (LLDP-Link zu
    #     einem anderen Node) fallen raus — sonst "hängt" das halbe Netz am
    #     Uplink —, ebenso die MACs der Nodes selbst. Sieht trotzdem mehr als
    #     ein Switch eine MAC (Filter-Lücke), gewinnt der Port mit den
    #     wenigsten gelernten MACs: der ist am nächsten am Gerät.
    node_macs = set()
    for key, node in nodes.items():
        for kandidat in [key] + node.get("aliase", []):
            if ":" in kandidat and not kandidat.startswith("ip:"):
                node_macs.add(kandidat)

    # Gateway-Adresse/-MAC schon hier bestimmen (braucht der Uplink-Rückfall
    # unten UND der Firewall-Node in 3c).
    fw_ip = fw_mac = ""
    if cfg.opnsense is not None:
        fw_ip = (cfg.opnsense.get("firewall_ip", "")
                 or urllib.parse.urlsplit(cfg.opnsense["url"]).hostname or "")
        fw_mac = lokale_arp_mac(fw_ip)

    fdb_lookup = {}  # mac -> (macs_am_port, node_key, port_name), ALLE MACs
    for key, daten in ergebnisse.items():
        uplinks = set()
        for nachbar in daten["nachbarn"]:
            n_key = aliase.get(nachbar_matchkey(nachbar), nachbar_matchkey(nachbar))
            if not n_key or n_key not in nodes:
                continue
            lokal = nachbar["lokalerPort"]
            if lokal in daten["ports"]:   # lldpLocPortNum == ifIndex (Netgear)
                uplinks.add(lokal)
            else:                          # sonst über den Port-Namen suchen
                name = daten["lokalePorts"].get(lokal, "")
                uplinks.update(i for i, p in daten["ports"].items()
                               if name and p["name"] == name)
        if not uplinks and fw_mac:
            # Switch ohne verwertbares LLDP (GS110TPv3): sein Uplink ist so
            # nicht erkennbar, und dort stehen die MACs des halben Netzes —
            # bei kleiner FDB "gewinnt" der Uplink sonst gegen die echten
            # Ports. Bester Anhaltspunkt: der Port mit der Gateway-MAC IST
            # der Uplink (jedes Gerät redet mit dem Gateway).
            uplinks.update(i for i, macs in daten.get("fdb", {}).items()
                           if fw_mac in macs)
        for if_index, macs in daten.get("fdb", {}).items():
            if if_index in uplinks or if_index not in daten["ports"]:
                continue
            port_name = daten["ports"][if_index]["name"] or f"Port {if_index}"
            for mac in macs:
                eintrag = (len(macs), key, port_name)
                if mac not in fdb_lookup or eintrag < fdb_lookup[mac]:
                    fdb_lookup[mac] = eintrag

    # Geräte-Zuordnung = alles außer der Infrastruktur selbst; deren MACs
    # bleiben in fdb_lookup und liefern die Kanten (Firewall, APs).
    zuordnung = {m: e for m, e in fdb_lookup.items() if m not in node_macs}

    # 3c) Phase 4 — OPNsense: ARP über alle Netze + DNS-Namen (Cache im
    #     State, 1 h). Die Firewall wird ein eigener Node; ihre Kante zum
    #     Switch liefert die FDB (der Port, an dem ihre MAC gelernt wurde).
    arp_csv, extra_links = [], []
    if cfg.opnsense is not None:
        arp = arp_einlesen(cfg, verbose)
        dns_cache = state.setdefault("dns", {})
        for eintrag in arp:
            treffer = dns_cache.get(eintrag["ip"])
            if treffer is None or jetzt - treffer.get("ts", 0) > 3600:
                treffer = {"name": dns_name(eintrag["ip"], cfg.dns_server)[:160], "ts": jetzt}
                dns_cache[eintrag["ip"]] = treffer
            arp_csv.append([eintrag["mac"], eintrag["ip"], treffer["name"]])
        for ip in [ip for ip, w in dns_cache.items() if jetzt - w.get("ts", 0) > 86400]:
            del dns_cache[ip]

        fw_key = next((k for k, n in nodes.items() if n.get("ip") == fw_ip), None)
        if fw_key is None:
            fw_key = f"ip:{fw_ip}"
            nodes[fw_key] = {"ip": fw_ip, "art": "firewall", "status": "aktiv",
                             "name": cfg.opnsense.get("name", "OPNsense"),
                             "modell": "", "firmware": "", "standort": "",
                             "fehlversuche": 0}
        else:
            nodes[fw_key].update({"art": "firewall", "status": "aktiv", "fehlversuche": 0})
        gesehen.add(fw_key)

        if fw_mac and fw_mac in fdb_lookup:
            _anzahl, sw_key, port_name = fdb_lookup[fw_mac]
            if sw_key != fw_key:
                extra_links.append([sw_key, port_name, fw_key, "", "", ""])

    # 3d) WC7500: verwaltete APs als Nodes (Kante zum Switch aus der FDB) und
    #     die eingebuchten Clients als WLAN-Zuordnung. Client-MACs fliegen aus
    #     der FDB-Zuordnung — der Switch "lernt" sie am AP-Port, aber gemeint
    #     ist: das Gerät hängt am AP.
    wlan_csv, wlan_macs = [], set()
    wlan_cfg = cfg.ini["wlan"] if "wlan" in cfg.ini else None
    if wlan_cfg is not None and wlan_cfg.get("controller_ip"):
        controller = wlan_cfg["controller_ip"]
        aps = wlan_tabelle(cfg, controller, "aps", verbose)
        clients = wlan_tabelle(cfg, controller, "clients", verbose)
        for ap in aps:
            verbunden = ap.get("status", "") == "Connected"
            key = next((k for k, n in nodes.items()
                        if k == ap["mac"] or (ap.get("ip") and n.get("ip") == ap["ip"])),
                       None)
            if key is None:
                key = ap["mac"]
                nodes[key] = {"ip": "", "art": "ap", "status": "aktiv", "name": "",
                              "modell": "", "firmware": "", "standort": "",
                              "fehlversuche": 0}
            node = nodes[key]
            node.update({"art": "ap",
                         "status": "aktiv" if verbunden else "stumm",
                         "ip": ap.get("ip") or node.get("ip", ""),
                         "name": ap.get("name") or node.get("name", ""),
                         "modell": ap.get("modell") or node.get("modell", ""),
                         "standort": ap.get("standort") or node.get("standort", "")})
            if verbunden:
                gesehen.add(key)
            treffer = fdb_lookup.get(ap["mac"])
            if treffer and treffer[1] != key:
                extra_links.append([treffer[1], treffer[2], key, "", "", ""])
        for client in clients:
            if not client.get("apIp"):
                continue
            wlan_macs.add(client["mac"])
            wlan_csv.append([client["mac"], client["apIp"],
                             client.get("apName", ""), client.get("ssid", "")])
            if client.get("ip"):   # MAC<->IP der Clients kennt nur der Controller
                arp_csv.append([client["mac"], client["ip"], ""])
        if verbose:
            log(f"  WC7500: {len(aps)} APs, {len(clients)} Clients")

    fdb_csv = [[key, port_name, mac]
               for mac, (_anzahl, key, port_name) in sorted(zuordnung.items())
               if mac not in wlan_macs]

    # 4) CSV-Zeilen bauen
    nodes_csv, ports_csv, links_csv = [], [], []
    for key, node in nodes.items():
        nodes_csv.append([key, node["art"], node.get("name"), node.get("ip"),
                          node.get("modell"), node.get("firmware"),
                          node.get("standort"), node["status"],
                          "1" if key in gesehen else "0"])

    kanten_gesehen = set()
    for key, daten in ergebnisse.items():
        for index, port in daten["ports"].items():
            ports_csv.append([key, index, port["name"], port["oper"], port["admin"],
                              port["speed"], port["inOctets"], port["outOctets"],
                              zeitstempel, port["inBps"], port["outBps"],
                              port["pvid"], port["vlanUntagged"], port["vlanTagged"]])
        for nachbar in daten["nachbarn"]:
            von_port = (daten["lokalePorts"].get(nachbar["lokalerPort"])
                        or f"Port {nachbar['lokalerPort']}")
            n_key = nachbar_matchkey(nachbar)
            n_key = aliase.get(n_key, n_key)
            if n_key and n_key in nodes:
                # Kante zwischen zwei Nodes nur einmal aufnehmen, auch wenn
                # beide Seiten sie melden
                paar = frozenset((key, n_key))
                if paar in kanten_gesehen:
                    continue
                kanten_gesehen.add(paar)
                links_csv.append([key, von_port, n_key,
                                  nachbar.get("portId", ""), "", ""])
            else:
                mac = ""
                if nachbar.get("chassisSubtype") == 4:
                    mac = mac_format(nachbar.get("chassisId") or b"")
                name = (nachbar.get("sysName")
                        or (nachbar.get("chassisId") or b"").decode("latin-1", "replace")
                        if nachbar.get("chassisSubtype") == 7 else nachbar.get("sysName", ""))
                links_csv.append([key, von_port, "", nachbar.get("portId", ""),
                                  mac, name])
    for link in extra_links:   # Firewall-/AP-Kanten aus der FDB
        paar = frozenset((link[0], link[2]))
        if paar not in kanten_gesehen:
            kanten_gesehen.add(paar)
            links_csv.append(link)

    # 5) Hochladen: Staging leeren -> freebcp -> MERGE
    csv_dir = os.path.join(cfg.state_dir, "csv")
    os.makedirs(csv_dir, exist_ok=True)
    schema = cfg.mssql["schema"]
    if not tsql_ausfuehren(cfg, f"USE [{cfg.mssql['database']}];\nGO\n"
                                f"TRUNCATE TABLE {schema}.network_nodes_stage;\n"
                                f"TRUNCATE TABLE {schema}.network_ports_stage;\n"
                                f"TRUNCATE TABLE {schema}.network_links_stage;\n"
                                f"TRUNCATE TABLE {schema}.network_fdb_stage;\n"
                                f"TRUNCATE TABLE {schema}.network_arp_stage;\n"
                                f"TRUNCATE TABLE {schema}.network_wlan_stage;",
                           "Staging leeren"):
        return 1

    for tabelle, zeilen in (("network_nodes_stage", nodes_csv),
                            ("network_ports_stage", ports_csv),
                            ("network_links_stage", links_csv),
                            ("network_fdb_stage", fdb_csv),
                            ("network_arp_stage", arp_csv),
                            ("network_wlan_stage", wlan_csv)):
        pfad = os.path.join(csv_dir, tabelle + ".csv")
        csv_schreiben(pfad, zeilen)
        if zeilen and not freebcp_hochladen(cfg, tabelle, pfad):
            return 1

    # Reihenfolge: erst Nodes/Ports/Kanten (2), dann ARP-Anreicherung (4),
    # dann die Zuordnung (3) — so nutzt der FDB-Join schon die frischen MACs.
    if not tsql_ausfuehren(cfg, sql_datei(cfg, "merge_phase2.sql"), "MERGE"):
        return 1
    if not tsql_ausfuehren(cfg, sql_datei(cfg, "merge_phase4.sql"), "MERGE Phase 4"):
        return 1
    if not tsql_ausfuehren(cfg, sql_datei(cfg, "merge_phase3.sql"), "MERGE Phase 3"):
        return 1
    if not tsql_ausfuehren(cfg, sql_datei(cfg, "merge_phase5.sql"), "MERGE Phase 5"):
        return 1

    state["nodes"] = nodes
    state_sichern(cfg, state)
    log(f"Lauf fertig: {len(ergebnisse)} Switches abgefragt, "
        f"{len(nodes)} Nodes gesamt, {len(ports_csv)} Ports, {len(links_csv)} Kanten, "
        f"{len(fdb_csv)} Geräte-Zuordnungen, {len(arp_csv)} ARP-Einträge, "
        f"{len(wlan_csv)} WLAN-Clients.")
    return 0


def opnsense_erkunden(cfg, pfad):
    """Kandidaten-Endpunkte der OPNsense-API durchprobieren und die Antwort-
    Struktur kompakt zeigen — Einmal-Werkzeug, um die Quelle für den
    Interface-Traffic zu finden (Gegenstück zu --wlan-erkunden).

    Ohne Argument: bekannte Kandidaten der Reihe nach. Mit Pfad-Argument
    (z. B. /api/diagnostics/traffic/interface): nur diesen, dafür mehr Inhalt."""
    if cfg.opnsense is None:
        sys.exit("FEHLER: Abschnitt [opnsense] fehlt in der Konfiguration.")

    kandidaten = [pfad] if pfad else [
        "/api/diagnostics/traffic/interface",
        "/api/diagnostics/interface/get_interface_statistics",
        "/api/diagnostics/interface/get_interface_names",
        "/api/diagnostics/interface/get_interface_config",
        "/api/interfaces/overview/interfaces_info",
        "/api/interfaces/overview/export",
    ]
    grenze = 4000 if pfad else 900

    for p in kandidaten:
        try:
            daten = opnsense_abfragen(cfg, p)
        except Exception as fehler:
            log(f"{p}: FEHLER {fehler}")
            continue
        log(f"{p}: OK ({type(daten).__name__})")
        if isinstance(daten, dict):
            log(f"  Schluessel: {', '.join(list(daten.keys())[:25])}")
        elif isinstance(daten, list):
            log(f"  Liste mit {len(daten)} Eintraegen")
        log("  " + json.dumps(daten, ensure_ascii=False)[:grenze])
    return 0


def wlan_erkunden(cfg, unter_oid):
    """Den Enterprise-Baum des WLAN-Controllers walken und die gefundenen
    Tabellen kompakt zeigen — Einmal-Werkzeug, um die OIDs der AP- und
    Client-Tabellen zu identifizieren (die MIB des WC7500 liegt nicht vor).

    Ohne Argument: grober Überblick (Gruppen in Tiefe 4). Mit einer OID als
    Argument (z. B. der Entry einer Tabelle wie ....8.6.4.1.1): dieser Ast
    SPALTENWEISE — je Spalte die Zeilenzahl und drei Beispielwerte."""
    if "wlan" not in cfg.ini or not cfg.ini["wlan"].get("controller_ip"):
        sys.exit("FEHLER: Abschnitt [wlan] mit controller_ip fehlt in der Konfiguration.")
    ip = cfg.ini["wlan"]["controller_ip"]
    if unter_oid:
        basis, tiefe = unter_oid.rstrip("."), 1
    else:
        basis, tiefe = cfg.ini["wlan"].get("basis_oid", ".1.3.6.1.4.1.4526.100.8"), 4
    log(f"Walke {basis} auf {ip} …")
    zeilen = snmp_abfrage("snmpbulkwalk", cfg.snmp_argumente(ip), ip, [basis], verbose=True)
    if not zeilen:
        sys.exit(f"FEHLER: {ip} liefert nichts unter {basis} "
                 f"(v2c-Community als [snmp:{ip}]-Abschnitt hinterlegt?).")

    gruppen = {}
    for oid, typ, roh in zeilen:
        rest = oid[len(basis) + 1:].split(".")
        praefix = ".".join(rest[:tiefe])
        gruppen.setdefault(praefix, []).append((oid, typ, wert_text(typ, roh)))

    log(f"{len(zeilen)} Werte, gruppiert nach {basis}.<Gruppe>:")
    for praefix in sorted(gruppen, key=lambda p: [int(x) for x in p.split(".") if x.isdigit()]):
        eintraege = gruppen[praefix]
        log(f"  .{praefix}  ({len(eintraege)} Zeilen)")
        for oid, typ, wert in eintraege[:3]:
            log(f"      {oid} = {typ}: {wert[:100]}")
    return 0


def main():
    parser = argparse.ArgumentParser(description="netmon-Collector (Phase 2+3)")
    parser.add_argument("--verbose", action="store_true", help="gesprächiger Handlauf")
    parser.add_argument("--init-db", action="store_true",
                        help="network_*-Tabellen anlegen/erweitern (mehrfach ausführbar)")
    parser.add_argument("--wlan-erkunden", nargs="?", const="", default=None,
                        metavar="OID",
                        help="Enterprise-Baum des WLAN-Controllers walken (OID-Suche); "
                             "mit OID-Argument: diesen Ast spaltenweise zeigen")
    parser.add_argument("--opnsense-erkunden", nargs="?", const="", default=None,
                        metavar="PFAD",
                        help="OPNsense-API-Endpunkte fuer Interface-Traffic durchprobieren; "
                             "mit Pfad-Argument: nur diesen, dafuer ausfuehrlicher")
    parser.add_argument("--config", default=CONFIG_PFAD)
    args = parser.parse_args()

    cfg = Config(args.config)
    if args.init_db:
        ok = all(tsql_ausfuehren(cfg, sql_datei(cfg, name), f"Tabellen anlegen ({name})")
                 for name in ("schema_phase2.sql", "schema_phase3.sql",
                              "schema_phase4.sql", "schema_phase5.sql",
                              "schema_vlan.sql"))
        log("Tabellen angelegt bzw. vorhanden." if ok else "Anlegen fehlgeschlagen.")
        return 0 if ok else 1
    if args.wlan_erkunden is not None:
        return wlan_erkunden(cfg, args.wlan_erkunden)
    if args.opnsense_erkunden is not None:
        return opnsense_erkunden(cfg, args.opnsense_erkunden)
    return sammellauf(cfg, args.verbose)


if __name__ == "__main__":
    sys.exit(main())
