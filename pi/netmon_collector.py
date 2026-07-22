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
import configparser
import json
import os
import re
import subprocess
import sys
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
        Ausnahme-Abschnitt [snmp:IP] (z. B. der S3300 mit SHA-512/AES)."""
        werte = dict(self.ini["snmp"])
        ausnahme = f"snmp:{ip}"
        if ausnahme in self.ini:
            werte.update(dict(self.ini[ausnahme]))
        return [
            "-v3", "-l", "authPriv",
            "-u", werte["user"],
            "-a", werte["auth_protocol"], "-A", werte["auth_password"],
            "-x", werte["priv_protocol"], "-X", werte["priv_password"],
            "-t", werte.get("timeout", "2"), "-r", werte.get("retries", "1"),
            "-On",
        ]

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
        name = werte.get("name", "")
        ist_physisch = bool(PHYS_PORT_RE.match(name))
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

        # Seed-Platzhalter (ip:...) auf die echte Chassis-MAC umziehen
        if daten["matchKey"] != key:
            nodes[daten["matchKey"]] = nodes.pop(key)
            if key in state["zaehler"]:
                state["zaehler"][daten["matchKey"]] = state["zaehler"].pop(key)
            key = daten["matchKey"]

        raten_berechnen(state, key, daten["ports"], jetzt)
        ergebnisse[key] = daten
        gesehen.add(key)
        if verbose:
            log(f"  {node['ip']} ({daten['name']}): {len(daten['ports'])} Ports, "
                f"{len(daten['nachbarn'])} LLDP-Nachbarn")

    # 3) Discovery: LLDP-Nachbarn mit Bridge-/AP-Fähigkeit, die noch fehlen
    for key, daten in ergebnisse.items():
        for nachbar in daten["nachbarn"]:
            bridge, ap = nachbar_faehigkeiten(nachbar)
            n_key = nachbar_matchkey(nachbar)
            if not n_key:
                continue
            if n_key in nodes:
                gesehen.add(n_key)
                if not nodes[n_key].get("ip") and nachbar.get("mgmtIp"):
                    nodes[n_key]["ip"] = nachbar["mgmtIp"]
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
                              zeitstempel, port["inBps"], port["outBps"]])
        for nachbar in daten["nachbarn"]:
            von_port = (daten["lokalePorts"].get(nachbar["lokalerPort"])
                        or f"Port {nachbar['lokalerPort']}")
            n_key = nachbar_matchkey(nachbar)
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

    # 5) Hochladen: Staging leeren -> freebcp -> MERGE
    csv_dir = os.path.join(cfg.state_dir, "csv")
    os.makedirs(csv_dir, exist_ok=True)
    schema = cfg.mssql["schema"]
    if not tsql_ausfuehren(cfg, f"USE [{cfg.mssql['database']}];\nGO\n"
                                f"TRUNCATE TABLE {schema}.network_nodes_stage;\n"
                                f"TRUNCATE TABLE {schema}.network_ports_stage;\n"
                                f"TRUNCATE TABLE {schema}.network_links_stage;",
                           "Staging leeren"):
        return 1

    for tabelle, zeilen in (("network_nodes_stage", nodes_csv),
                            ("network_ports_stage", ports_csv),
                            ("network_links_stage", links_csv)):
        pfad = os.path.join(csv_dir, tabelle + ".csv")
        csv_schreiben(pfad, zeilen)
        if zeilen and not freebcp_hochladen(cfg, tabelle, pfad):
            return 1

    if not tsql_ausfuehren(cfg, sql_datei(cfg, "merge_phase2.sql"), "MERGE"):
        return 1

    state["nodes"] = nodes
    state_sichern(cfg, state)
    log(f"Lauf fertig: {len(ergebnisse)} Switches abgefragt, "
        f"{len(nodes)} Nodes gesamt, {len(ports_csv)} Ports, {len(links_csv)} Kanten.")
    return 0


def main():
    parser = argparse.ArgumentParser(description="netmon-Collector (Phase 2)")
    parser.add_argument("--verbose", action="store_true", help="gesprächiger Handlauf")
    parser.add_argument("--init-db", action="store_true",
                        help="network_*-Tabellen einmalig anlegen")
    parser.add_argument("--config", default=CONFIG_PFAD)
    args = parser.parse_args()

    cfg = Config(args.config)
    if args.init_db:
        ok = tsql_ausfuehren(cfg, sql_datei(cfg, "schema_phase2.sql"), "Tabellen anlegen")
        log("Tabellen angelegt bzw. vorhanden." if ok else "Anlegen fehlgeschlagen.")
        return 0 if ok else 1
    return sammellauf(cfg, args.verbose)


if __name__ == "__main__":
    sys.exit(main())
