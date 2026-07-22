# Collector-Installation auf dem Raspberry Pi (Phase 2)

Der Collector fragt die Switches per SNMPv3 ab (System-Info, Ports samt
Traffic-Zählern, LLDP-Topologie), entdeckt weitere Switches selbstständig und
schreibt alles per `freebcp`/`tsql` in die MSSQL — dasselbe Muster wie das
bestehende `netscan.sh`, das unverändert weiterläuft.

**Voraussetzungen** (auf dem netscan-Pi bereits vorhanden): Python 3,
`snmp` (net-snmp-Tools), `freetds-bin` (freebcp/tsql).

## 1. Dateien an ihren Platz

```bash
sudo install -m 700 -o root -g root netmon_collector.py /usr/local/sbin/netmon_collector.py
sudo install -d -m 755 /usr/local/share/netmon /var/lib/netmon /var/log/netmon
sudo install -m 644 schema_phase2.sql merge_phase2.sql /usr/local/share/netmon/
```

## 2. Konfiguration

```bash
sudo install -d -m 700 /etc/netmon
sudo install -m 600 -o root -g root netmon.conf.example /etc/netmon/netmon.conf
sudo nano /etc/netmon/netmon.conf
```

Auszufüllen:

- **[snmp]** — die SNMPv3-Zugangsdaten des `netmon`-Benutzers
  (dieselben Werte wie in `~/.snmp/snmp.conf`).
- **[snmp:…]-Ausnahmen** — Hosts, die vom Standard abweichen
  (S3300: `SHA-512` + `AES`, steht schon als Beispiel drin).
- **[mssql]** — Server/Benutzer/Passwort wie in `/usr/local/sbin/netscan.sh`.
- **[discovery] seed_ips** — ein erreichbarer Switch genügt (Masterswitch);
  alles Weitere findet die LLDP-Discovery selbst.

## 3. Tabellen anlegen (einmalig)

```bash
sudo /usr/local/sbin/netmon_collector.py --init-db
```

Legt `network_nodes`, `network_ports`, `network_links` samt `_stage`-Zwillingen
an (mehrfach ausführbar, überschreibt nichts).

## 4. Probelauf von Hand

```bash
sudo /usr/local/sbin/netmon_collector.py --verbose
```

Erwartung: je Switch eine Zeile mit Port-/Nachbar-Zahlen, ggf.
`Entdeckt (switch): …`-Meldungen für Switches hinter Switches, am Ende
`Lauf fertig: …`. Die Raten (`inBps`/`outBps`) sind beim **ersten** Lauf leer —
sie entstehen aus der Differenz zweier Läufe.

## 5. Cron einrichten

```bash
sudo install -m 644 netmon.cron.example /etc/cron.d/netmon
```

Läuft alle 5 Minuten, um 2 Minuten versetzt zu `netscan.sh`. Log:
`/var/log/netmon/collector.log`.

## Wie die Discovery arbeitet

- LLDP-Nachbarn mit **Bridge-Fähigkeit**, die noch unbekannt sind, werden als
  Node `status = entdeckt` angelegt (Name/IP aus LLDP). Das Intranet zeigt für
  sie einen Installationshinweis.
- Auf jedem `entdeckt`-Switch mit bekannter IP klopft der Collector **jeden
  Lauf einmal an**. Sobald dort der `netmon`-Benutzer existiert (Read-Only,
  authPriv, wie auf den anderen — und auf M4300/M4200 **Save Config** nicht
  vergessen!), wird er automatisch `aktiv` und voll abgefragt. Keine
  IP-Pflege von Hand.
- Antwortet ein aktiver Switch 3 Läufe lang nicht, wird er `stumm` (und bei
  der nächsten Antwort wieder `aktiv`).
- LLDP-sprechende Endgeräte (PCs, Telefone) werden **keine** Nodes, sondern
  Kanten mit `zu_fremd_mac`/`zu_fremd_name` — so bleiben z. B. die Rechner
  hinter einem unmanaged Verteiler trotzdem auf der Karte sichtbar.

## Fehlersuche

- `FEHLER bei MERGE`/`freebcp` im Log → Zugangsdaten in `[mssql]` prüfen;
  Merkfalle: Die DB heißt `LINEAR`, sonst landet man in `master`.
- Ein Switch fehlt → antwortet er auf `snmpget <IP> 1.3.6.1.2.1.1.5.0`
  (mit `~/.snmp/snmp.conf`)? S3300-artige Geräte brauchen einen
  `[snmp:IP]`-Ausnahmeabschnitt.
- Zwischenstände: `/var/lib/netmon/state.json` (bekannte Nodes + Zählerstände),
  CSV-Dateien des letzten Laufs unter `/var/lib/netmon/csv/`.
