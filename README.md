# Netzwerk-Modul (`do1emu/module-netzwerk`)

Modul für die modulare Intranet-Plattform: zeigt das **Inventar und die Karte des
lokalen Netzwerks** – welche Geräte es gibt, wo sie hängen, ob sie online sind.

**Seiten:** *Karte* (Topologie-Baum aus LLDP-Daten: Switches mit Portanzahl,
aktueller Rate, Fremd-Nachbarn und Redundanz-Querverbindungen; neu entdeckte,
noch nicht abfragbare Geräte erscheinen amber mit Einbindungs-Anleitung),
*Geräte* (Endgeräte-Inventar aus dem Ping-Scan, je Netzsegment, mit Spalten
„Anschluss", Typ, Standort und Info) und je Knoten eine **Detailseite**
(Portleiste mit Status/Speed/Rate/Uplinks und VLANs („1U, 10T" =
untagged/tagged), angeschlossene Geräte je Port, WLAN-Clients bei APs,
Nachbarn, Link zum Webinterface des Geräts) – verlinkt aus Karte und
Geräteliste.

**Pflege-Daten:** Zu jedem Gerät (Endgerät wie Infrastruktur-Knoten) lassen
sich **Gerätetyp**, **Standort** und ein freies **Info-Feld** hinterlegen –
Stift-Symbol in der Geräteliste bzw. „bearbeiten" auf der Knoten-Detailseite.
Standorte sind eine **Hierarchie** (Gebäude → Stockwerke → Räume, jede Ebene
einzeln pflegbar, Räume per Komma in Serie); am Gerät wählt man einen Punkt
daraus (ganzes Gebäude, Stockwerk oder Raum). Typen und Standorte verwaltet
je ein eigener Menüpunkt (CRUD). Diese Daten liegen in der
**Instanz-Datenbank** (`netzwerk_geraetetypen`, `netzwerk_gebaeude`,
`netzwerk_stockwerke`, `netzwerk_raeume`, `netzwerk_geraete`, verknüpft lose
über MAC bzw. IP) – die MSSQL-Quelle bleibt read-only. Viele Typen erkennt
das Modul automatisch (Knoten-Art, Hersteller, Hostname); erkannte Typen
erscheinen kursiv und werden beim Speichern im Formular übernommen.

Das Modul **liest nur**. Erhoben werden die Daten von einem externen Collector
(bei uns: ein Raspberry Pi im Netz, der per nmap und SNMP scannt) und in eine
MSSQL-Datenbank geschrieben; das Intranet kennt keinerlei Zugangsdaten zu
Netzwerkgeräten.

## Installation

```bash
composer require do1emu/module-netzwerk
php artisan modules:sync
php artisan migrate
```

Danach in der `.env` die Datenquelle hinterlegen (ODBC-Weg, empfohlen):

```dotenv
NETZWERK_DB_DSN="Driver={ODBC Driver 18 for SQL Server};Server=host,1433;Database=meinedb;TrustServerCertificate=yes"
NETZWERK_DB_USERNAME=leser
NETZWERK_DB_PASSWORD=...
# optional (Standard: Ekkon3):
# NETZWERK_DB_SCHEMA=Ekkon3
```

Alternativ nativ über `pdo_sqlsrv` mit `NETZWERK_DB_HOST` / `NETZWERK_DB_PORT` /
`NETZWERK_DB_DATABASE` – Details in [config/netzwerk.php](config/netzwerk.php).

Ohne Konfiguration zeigt das Modul einen Hinweis statt Fehlern.

**Sichtbarkeit:** Der Menüpunkt startet ohne Rollen-Freigabe (= nur Admins).
Die Übersicht zeigt Netz-Interna (IPs, MACs, Hostnamen) – bewusst je Rolle
freischalten unter *Verwaltung → Module → Netzwerk*.

Für die lokale Entwicklung ohne erreichbare MSSQL-Quelle zeigt `NETZWERK_DEMO=true`
erfundene Beispieldaten auf der Karte (niemals auf einem Server setzen).

## Datenmodell

Gelesen werden aus `{schema}`:

- `network_devices` – Endgeräte-Inventar (Ping-Scan): `mac, ip, segment,
  hostname, vendor, firstSeen, lastSeen`
- `network_nodes` – Infrastruktur (Switches, APs, Controller, Firewall) mit
  `status` (`aktiv` | `entdeckt` | `stumm`)
- `network_links` – LLDP-Kanten (inkl. Fremd-Nachbarn ohne eigenen Node)
- `network_ports` – Ports je Node mit Status, Speed, aktueller Rate (bit/s)
  und VLAN-Mitgliedschaften (`pvid`, `vlanUntagged`, `vlanTagged`; leer bei
  Geräten ohne Q-BRIDGE-MIB)

Das Schema samt Staging-Tabellen und MERGE liegt unter [pi/](pi/) beim
Collector. „online" berechnet das Modul beim Lesen aus `lastSeen` (Standard:
15 Minuten, `NETZWERK_OFFLINE_AB_MINUTEN`), nicht aus gespeicherten Flags –
fällt der Collector aus, zeigt die Übersicht ehrlich offline.

## Ausbaustufen

1. ✅ Geräte-Inventar
2. ✅ Netzwerkkarte aus LLDP-Daten (Topologie-Baum, Discovery unbekannter Switches)
3. ✅ Gerät-zu-Switchport-Zuordnung (FDB) und WLAN (WC7500: APs als Karten-Knoten,
   Clients mit „AP + SSID") – Spalte „Anschluss" in der Geräteliste
4. ✅ OPNsense-ARP + DNS-Namen (MACs/Namen über alle Netze, Firewall auf der Karte;
   die Firewall-Interfaces erscheinen als Ports ihres Knotens)
5. ✅ Traffic-Statistiken – Seite „Statistik": Verläufe je Port (24 h / 7 Tage / 30 Tage)
   aus `network_port_stats`, serverseitig gerenderte SVG-Charts
6. ✅ Alarme – ist die Ekkon-Basis (`do1emu/module-ekkon`) installiert, registriert das
   Modul den Task **Netzwerk/Alarme**: meldet über die Ekkon-Benachrichtigungsrouten,
   wenn ein eingebundener Knoten nicht mehr antwortet (Schwelle einstellbar), wieder
   erreichbar ist (abschaltbar) oder ein neues Gerät entdeckt wurde. Der erste Lauf
   merkt sich nur die Ausgangslage. ⚠️ Ohne eingerichtete Route (Ekkon →
   Benachrichtigungen) erreichen die Meldungen niemanden — der Task weist in seiner
   Lauf-Historie darauf hin.

Das Konzept samt Collector-Beschreibung liegt bei der betreibenden Instanz
(Erst-Einsatz: Waldorfschule).
