# Netzwerk-Modul (`do1emu/module-netzwerk`)

Modul für die modulare Intranet-Plattform: zeigt das **Inventar und (perspektivisch)
die Karte des lokalen Netzwerks** – welche Geräte es gibt, wo sie hängen, ob sie
online sind.

Das Modul **liest nur**. Erhoben werden die Daten von einem externen Collector
(bei uns: ein Raspberry Pi im Netz, der per nmap und SNMP scannt) und in eine
MSSQL-Datenbank geschrieben; das Intranet kennt keinerlei Zugangsdaten zu
Netzwerkgeräten.

## Installation

```bash
composer require do1emu/module-netzwerk
php artisan modules:sync
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

## Datenmodell (Stand Phase 1)

Gelesen wird `{schema}.network_devices` (Endgeräte-Inventar):
`matchKey, mac, ip, segment, hostname, vendor, firstSeen, lastSeen, isOnline`.

„online" berechnet das Modul beim Lesen aus `lastSeen` (Standard: 15 Minuten,
`NETZWERK_OFFLINE_AB_MINUTEN`), nicht aus dem gespeicherten `isOnline` – fällt
der Collector aus, zeigt die Übersicht ehrlich offline.

## Ausbaustufen

1. ✅ Geräte-Inventar (dieses Paket)
2. Netzwerkkarte aus LLDP-Daten (Switches, Topologie, Discovery unbekannter Switches)
3. Gerät-zu-Switchport-Zuordnung (FDB), WLAN (Controller/APs)
4. Traffic-Statistiken

Das Konzept samt Collector-Beschreibung liegt bei der betreibenden Instanz
(Erst-Einsatz: Waldorfschule).
