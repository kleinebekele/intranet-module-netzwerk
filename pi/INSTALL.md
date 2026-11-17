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
sudo install -m 644 schema_phase*.sql merge_phase*.sql /usr/local/share/netmon/
```

Nach jedem Update mit neuen/geänderten SQL-Dateien einmal `--init-db` laufen
lassen (mehrfach ausführbar, legt nur an bzw. erweitert, was fehlt).

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

## Phase 3: Gerät-zu-Port-Zuordnung (FDB)

Jeder Sammellauf liest zusätzlich die MAC-Tabellen (FDB) der aktiven Switches
und ordnet die Endgeräte aus `network_devices` ihrem Switch-Port zu (Spalten
`node_id`/`port_name`/`verbunden_via`/`zugeordnet_am`). Uplink-Ports (LLDP-Link
zu einem anderen Node) werden ignoriert; sieht mehr als ein Switch eine MAC,
gewinnt der Port mit den wenigsten gelernten MACs. Ein Gerät, das gerade
schweigt, behält seine letzte Zuordnung — `zugeordnet_am` sagt, von wann sie
stammt. Zuordnen lässt sich nur, was eine MAC hat (praktisch: das lokale
`.0`-Netz; geroutete Netze kommen mit Phase 4/ARP).

**WLAN (WC7500):** Mit `controller_ip` im `[wlan]`-Abschnitt (plus dessen
v2c-Community als `[snmp:IP]`-Abschnitt) wird der Controller selbst ein Node
(Art `controller`: Karte, Detailseite mit seinen Ethernet-Ports, Alarm bei
Ausfall; Kennung = LAN-MAC aus `ifPhysAddress`, da er kein LLDP spricht; Kante
zum Switch aus der FDB), und jeder Lauf liest die AP- und die Client-Tabelle
des Controllers: Die verwalteten APs werden Nodes (Name, IP,
Modell, Standort, Status; Kante zum Switch aus der FDB — der Port, an dem die
LAN-MAC des AP gelernt wurde), und eingebuchte Clients bekommen statt des
Switch-Ports ihre WLAN-Zuordnung „AP + SSID" (die gewinnt gegen die FDB, denn
der Switch lernt Client-MACs nur stellvertretend am AP-Port). Zusätzlich landet
je Lauf ein Schnappschuss ALLER eingebuchten Clients in `network_wlan_clients`
(komplett ersetzt; auch Geräte, die nie per nmap/ARP erfasst wurden) — darauf
baut der Andrang-Alarm des Moduls (Task Netzwerk/Alarme, „mehr als N Geräte
in SSID X"). Tabelle kommt mit `--init-db`.

`--wlan-erkunden [OID]` walkt den Enterprise-Baum des Controllers und zeigt die
Tabellen gruppiert (mit OID-Argument: spaltenweise) — Werkzeug, mit dem die
Spaltenbelegung am echten Gerät ausgemessen wurde (2026-07-25); nützlich, falls
je ein anderes Controller-Modell die Struktur ändert.

## Phase 4: OPNsense-ARP + DNS-Namen

Mit einem `[opnsense]`-Abschnitt (API-URL, Key/Secret des Anzeige-Benutzers)
liest jeder Lauf zusätzlich die ARP-Tabelle der Firewall: MAC↔IP über **alle**
Netze. Damit bekommen Geräte in gerouteten Netzen ihre MAC nachgetragen — und
darüber im selben Lauf ihre Port-Zuordnung. Namen kommen per DNS-Reverse
(`[dns] server =` Domaincontroller; braucht `dig`, sonst System-Resolver);
beides wird nur **ergänzt**, nie überschrieben. Die Firewall erscheint als
eigener Node auf der Karte; ihre Kante zum Switch leitet der Collector aus der
FDB ab (der Port, an dem ihre MAC gelernt wurde). DNS-Antworten werden eine
Stunde im State zwischengespeichert.

**Lebenszeichen ohne nmap:** Wer in der ARP-Tabelle oder beim WLAN-Controller
steht, bekommt `lastSeen` und seine aktuelle IP fortgeschrieben; unbekannte
MACs werden als neue Geräte angelegt (nur in Netzen, die das Inventar schon
kennt, `segment` = /24). Damit hält der Collector das Geräte-Inventar allein
aktuell – `netscan.sh` ist nicht mehr nötig. Geräte, die nie über die Firewall
sprechen, hält Phase 3 über die Switch-Tabellen (FDB) online: Steht ihre MAC
dort, gilt das als Lebenszeichen (nur `lastSeen`, die IP bleibt). Ganz stumme
Geräte (tiefer Standby) fallen nach der FDB-Alterung heraus.

## VLANs je Port

Jeder Sammellauf liest zusätzlich die Q-BRIDGE-VLAN-Tabellen der Switches
(`dot1qVlanStaticTable`, Rückfall auf die Current-Tabelle) plus den PVID je
Port und schreibt die Mitgliedschaften in `network_ports.vlanUntagged` /
`vlanTagged` (Komma-Listen; `pvid` zusätzlich als Zahl). Tagged = in der
Egress-, aber nicht in der Untagged-Maske. Geräte ohne Q-BRIDGE (GS110TPv3)
liefern nichts — dort bleiben die Spalten leer, und ein einzelner
SNMP-Aussetzer wischt den letzten bekannten Stand nicht weg (COALESCE im
MERGE).

**Update-Hinweis:** Collector-Script und SQL-Dateien zusammen installieren und
direkt danach `--init-db` ausführen (legt die neuen Spalten an). Startet genau
in diesem Moment ein Cron-Lauf, scheitert höchstens dieser eine — der nächste
läuft wieder sauber durch.

## OPNsense-Interface-Traffic

Mit `[opnsense]`-Abschnitt liest jeder Lauf zusätzlich
`/api/diagnostics/traffic/interface` (ACL „Reporting: Traffic") und schreibt
die **zugewiesenen Interfaces der Firewall als Ports ihres Nodes** in
`network_ports`: Name = vergebene Beschreibung, ifIndex = FreeBSD-Index,
Status aus Link-State/Flags, Raten aus der Byte-Zähler-Differenz (gemeinsamer
State mit den Switches). Loopback/pflog/pfsync/enc werden übersprungen,
VLAN-Spalten bleiben leer. Damit zeigen Karte, Knoten-Detailseite und
Statistik die Firewall wie jeden Switch — ganz ohne SNMP auf der OPNsense.
`--opnsense-erkunden [PFAD]` ist das zugehörige Erkundungswerkzeug.

## Phase 5: Traffic-Historie

`network_port_stats` bekommt je Lauf und **aktivem** Port eine Zeile mit den
aktuellen Raten (Zeitstempel = Zählerstand des Laufs). Aufbewahrung 30 Tage;
das Aufräumen (Altdaten + Zeilen verwaister Ports) erledigt `merge_phase5.sql`
bei jedem Lauf mit. Achtung Reihenfolge: `merge_phase2.sql` lässt die Stages
seit Phase 5 bewusst stehen — aufgeräumt wird erst am Ende von
`merge_phase5.sql`.

## Phase 6: WLAN-Auswertung

Die Client-Tabelle des WC7500 liefert je Client auch die IP. Seit Phase 6
landet sie im Schnappschuss (`network_wlan_clients.ip`), und `merge_phase3.sql`
führt zwei Verläufe (Aufbewahrung 60 Tage):

- `network_wlan_wechsel` — ein Client steht im neuen Lauf an einem anderen AP
  als im letzten (nur gegen einen höchstens 15 Minuten alten Schnappschuss).
- `network_wlan_ohne_ip` — eingebucht mit 169.254.x.x (kein DHCP), eine Zeile
  je ununterbrochenem Zeitraum.

169.254-Adressen gehen bewusst nicht mehr in `network_arp_stage`, damit sie die
echte Adresse eines Geräts im Inventar nicht überschreiben. Das Intranet zeigt
beides unter Netzwerk → WLAN; der Task Netzwerk/Alarme meldet Geräte ohne
Adresse (Meldungsart `netzwerk-wlan-ohne-adresse`).

**Update:** Collector-Script und SQL-Dateien zusammen installieren, direkt
danach `--init-db` (hängt die Spalte `ip` an Stage und Schnappschuss an und
legt die Verlaufstabellen an).

## Syslog-Empfang (Stufe 1: Rohmitschnitt)

Der Collector sieht nur alle 5 Minuten hin. Was der WLAN-Controller im
Sekundentakt meldet (Anmeldung, AP-Wechsel, Sperre wegen schwachem Signal),
kommt nur per Syslog. `netmon_syslog.py` lauscht auf UDP 514 und schreibt jede
Meldung mit Empfangszeit und Absender in eine Tagesdatei
(`/var/log/netmon/syslog/netmon-syslog-JJJJ-MM-TT.log`, 14 Tage). Die
In die MSSQL bringt sie der Syslog-Import (Stufe 2, unten).

```bash
sudo install -m 700 -o root -g root netmon_syslog.py /usr/local/sbin/netmon_syslog.py
sudo install -m 644 netmon-syslog.cron.example /etc/cron.d/netmon-syslog
```

Kein Dienst: Cron versucht jede Minute einen Start, `flock -n` lässt ihn
sofort enden, solange der Empfänger läuft. Optional in `/etc/netmon/netmon.conf`
den Abschnitt `[syslog]` setzen (`erlaubt` = IP des Controllers, damit nur er
mitgeschrieben wird). Danach den Empfänger einmal beenden
(`pkill -f netmon_syslog.py`), Cron startet ihn mit der neuen Konfiguration.

Prüfen: `netmon_syslog.py --test` schickt eine Probemeldung an den eigenen Port
(nur sichtbar, wenn `127.0.0.1` erlaubt ist oder `erlaubt` fehlt);
`tail -f /var/log/netmon/syslog/netmon-syslog-$(date +%F).log`.
Am Controller: Syslog an, Ziel = IP dieses Containers, Port 514. Die Firewall
muss UDP 514 vom Controller zum Container durchlassen.

Netgear WC7500: Von selbst schickt er per Syslog nur die Meldungen seines
eigenen DHCP-Servers (`dhcpd: …`). Die Client-Ereignisse (Load Balancing
„Bad RSSI“, Blacklist, Assoc) kommen erst, wenn unter Configuration → System →
Alerts/Logs → Alarms die Stufen **Minor** und **Normal** auf „Add To Syslog“
stehen. Sie erscheinen als `Alarm of Category: …, Severity: …, Descr: [...]`.
Die Zeit im Text ist die Ortszeit des Controllers, die Empfangszeit davor die
Uhr des Containers (dort UTC). Major und Critical ebenfalls auf „Add To Syslog“
stellen: Sie landen dann als Controller-Alarme auf der WLAN-Seite.

## Syslog-Import (Stufe 2: Ereignisse in die MSSQL)

`netmon_collector.py --syslog-import` liest die Tagesdateien ab der zuletzt
gelesenen Stelle (`/var/lib/netmon/syslog_import.json`), zerlegt die Zeilen und
lädt sie nach `network_wlan_ereignis` (60 Tage):
Bad-RSSI-Abweisungen (Client, Signal, Schwelle, AP), alle übrigen
Controller-Alarme (Kategorie, Stufe, Text) und das DHCP des Controllers
(DISCOVER … ACK/NAK, verworfene Adressen). Die AP-MAC der Abweisungen wird
über `/var/lib/netmon/wlan_aps.json` zum AP-Namen — die Datei schreibt der
normale Sammellauf. Die Leseposition rückt nur nach erfolgreichem Upload vor.

Einrichten (nach dem Update von `netmon_collector.py` und den SQL-Dateien):

```bash
sudo /usr/local/sbin/netmon_collector.py --init-db
sudo install -m 644 netmon-syslog.cron.example /etc/cron.d/netmon-syslog
```

Der Cron-Eintrag enthält dann eine zweite Zeile: alle 5 Minuten (um 1 versetzt)
den Import. Prüfen von Hand: `sudo /usr/local/sbin/netmon_collector.py
--syslog-import --verbose`; Protokoll unter `/var/log/netmon/syslog-import.log`.

## Zugangsdaten nicht in der Prozessliste

Bis Oktober 2026 standen SNMP-Passphrasen/Community und das MSSQL-Passwort als
Kommandozeilen-Argumente (`-A`/`-X`/`-c`, `-P`) in den Aufrufen von
`snmpget`/`snmpbulkwalk`, `tsql` und `freebcp` — per `ps` bzw.
`/proc/<pid>/cmdline` für **jeden** lokalen Benutzer lesbar. Seitdem:

- **SNMP:** Der Collector schreibt je Zugangsdatensatz (Standard `[snmp]`,
  jede abweichende `[snmp:IP]`-Ausnahme) eine temporäre `snmp.conf`
  (`defVersion`, `defSecurityName`, `defSecurityLevel`, `defAuthType`,
  `defAuthPassphrase`, `defPrivType`, `defPrivPassphrase` bzw. bei v2c
  `defCommunity`) in ein privates Verzeichnis `/tmp/netmon-snmp-XXXX/`
  (0700, Dateien 0600) und übergibt es per Umgebungsvariable `SNMPCONFPATH`.
  In argv stehen nur noch `-t`, `-r`, `-On`, IP und OIDs. Das Verzeichnis wird
  am Laufende gelöscht (auch bei Fehler oder SIGTERM); Reste eines hart
  abgebrochenen Laufs räumt der nächste Lauf nach 6 Stunden ab. Mit gesetztem
  `SNMPCONFPATH` ignoriert net-snmp `~/.snmp/snmp.conf` — die bleibt nur für
  Handtests (`snmpget <IP> …`) zuständig.
- **MSSQL:** `tsql` und `freebcp` bekommen `-P -` und lesen das Passwort aus
  der ersten stdin-Zeile (FreeTDS ab 1.1, Debian 12 liefert 1.3.17). Eine
  Umgebungsvariable fürs Passwort kennt FreeTDS nicht. Passwort: max. 254
  Zeichen, kein Zeilenumbruch.

**Installieren muss man nichts** — es bleibt bei `snmp`, `freetds-bin`,
`python3`. Update wie gewohnt: `netmon_collector.py` nach
`/usr/local/sbin/` (`install -m 700 -o root -g root …`), kein `--init-db`
nötig, kein Dienst-Neustart (Cron startet jeden Lauf neu).

**Prüfen** (während ein Lauf aktiv ist, z. B. parallel zu
`sudo /usr/local/sbin/netmon_collector.py --verbose`):

```bash
ps -eo args | grep -iE 'snmp|tsql|freebcp' | grep -v grep
```

zeigt kein Passwort/keine Community mehr (bei `tsql`/`freebcp` steht
`-P -`). Nach dem Lauf ist `ls -d /tmp/netmon-snmp-*` leer.

## Fehlersuche

- `FEHLER bei MERGE`/`freebcp` im Log → Zugangsdaten in `[mssql]` prüfen;
  Merkfalle: Die DB heißt `LINEAR`, sonst landet man in `master`.
- Ein Switch fehlt → antwortet er auf `snmpget <IP> 1.3.6.1.2.1.1.5.0`
  (mit `~/.snmp/snmp.conf`)? S3300-artige Geräte brauchen einen
  `[snmp:IP]`-Ausnahmeabschnitt.
- Ein Gerät steht **doppelt** in der Liste (einmal aktiv, einmal „entdeckt") →
  es war unter zwei Kennungen bekannt (LLDP-Chassis-MAC der Nachbarn vs.
  eigene Poll-Kennung). Der Collector führt Einträge mit derselben IP seit
  Ende Juli 2026 selbst zusammen und räumt die verwaiste DB-Zeile im nächsten
  Lauf ab — es reicht also, einen Lauf abzuwarten.
- Zwischenstände: `/var/lib/netmon/state.json` (bekannte Nodes + Zählerstände),
  CSV-Dateien des letzten Laufs unter `/var/lib/netmon/csv/`.
