#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
netmon_syslog.py — Syslog-Empfang des Netzwerk-Moduls (Stufe 1: Rohmitschnitt).

Lauscht per UDP (Standard 514) und schreibt jede Meldung mit Empfangszeit und
Absender-IP in eine Tagesdatei unter log_dir (netmon-syslog-JJJJ-MM-TT.log).
Zweck: sehen, was der WLAN-Controller an Client-Ereignissen schickt
(Anmeldung, AP-Wechsel, Sperre) — mit Sekunden, die der 5-Minuten-Collector
nicht sieht. Die Auswertung in die MSSQL folgt, sobald das Format bekannt ist.

Ohne Dienst-Manager: Cron startet das Skript jede Minute über `flock -n`.
Läuft schon eins, endet der neue Aufruf sofort; nach Absturz oder Neustart
des Containers ist der Empfang spätestens nach einer Minute wieder da.

Konfiguration (optional) in /etc/netmon/netmon.conf, Abschnitt [syslog]:
    port = 514
    erlaubt = 10.10.0.181          # Absender-IPs, Komma-getrennt; leer = alle
    log_dir = /var/log/netmon/syslog
    tage = 14                      # so lange bleiben Tagesdateien liegen

Aufrufe:
    netmon_syslog.py               Empfang (Cron)
    netmon_syslog.py --test        schickt eine Probemeldung an den eigenen Port
"""

import argparse
import configparser
import glob
import os
import signal
import socket
import sys
import time
from datetime import datetime

KONFIG = os.environ.get('NETMON_CONF', '/etc/netmon/netmon.conf')


def lies_konfig():
    cp = configparser.ConfigParser(inline_comment_prefixes=('#', ';'))
    try:
        cp.read(KONFIG, encoding='utf-8')
    except (OSError, configparser.Error) as fehler:
        print(f'{datetime.now():%Y-%m-%d %H:%M:%S} Konfiguration nicht lesbar: {fehler}', flush=True)
    s = cp['syslog'] if cp.has_section('syslog') else {}
    erlaubt = {ip.strip() for ip in str(s.get('erlaubt', '')).split(',') if ip.strip()}
    return {
        'port': int(s.get('port', 514)),
        'erlaubt': erlaubt,
        'log_dir': str(s.get('log_dir', '/var/log/netmon/syslog')),
        'tage': max(1, int(s.get('tage', 14))),
    }


class Tagesdatei:
    """Hängt Zeilen an die Datei des aktuellen Tages an; räumt alte Tage ab."""

    def __init__(self, verzeichnis, tage):
        self.verzeichnis = verzeichnis
        self.tage = tage
        self.tag = None
        self.datei = None
        os.makedirs(verzeichnis, mode=0o750, exist_ok=True)

    def schreibe(self, zeile):
        heute = datetime.now().strftime('%Y-%m-%d')
        if heute != self.tag:
            if self.datei:
                self.datei.close()
            self.tag = heute
            pfad = os.path.join(self.verzeichnis, f'netmon-syslog-{heute}.log')
            self.datei = open(pfad, 'a', encoding='utf-8', buffering=1)
            self.aufraeumen()
        self.datei.write(zeile + '\n')

    def aufraeumen(self):
        grenze = time.time() - self.tage * 86400
        for pfad in glob.glob(os.path.join(self.verzeichnis, 'netmon-syslog-*.log')):
            try:
                if os.path.getmtime(pfad) < grenze:
                    os.remove(pfad)
            except OSError:
                pass

    def schliessen(self):
        if self.datei:
            self.datei.close()
            self.datei = None


def empfang(k):
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    try:
        sock.bind(('0.0.0.0', k['port']))
    except OSError as fehler:
        print(f'{datetime.now():%Y-%m-%d %H:%M:%S} Port {k["port"]} nicht belegbar: {fehler}', flush=True)
        return 1

    ziel = Tagesdatei(k['log_dir'], k['tage'])
    abgewiesen = {}

    def ende(*_):
        ziel.schliessen()
        sock.close()
        sys.exit(0)

    signal.signal(signal.SIGTERM, ende)
    signal.signal(signal.SIGINT, ende)
    print(f'{datetime.now():%Y-%m-%d %H:%M:%S} Empfang läuft auf UDP {k["port"]}'
          + (f', erlaubt: {", ".join(sorted(k["erlaubt"]))}' if k['erlaubt'] else ', alle Absender'), flush=True)

    while True:
        daten, (ip, _port) = sock.recvfrom(8192)
        if k['erlaubt'] and ip not in k['erlaubt']:
            # Fremde Absender nur einmal je Stunde melden, nicht mitschreiben.
            jetzt = time.time()
            if jetzt - abgewiesen.get(ip, 0) > 3600:
                abgewiesen[ip] = jetzt
                print(f'{datetime.now():%Y-%m-%d %H:%M:%S} Meldung von nicht erlaubtem Absender {ip} verworfen', flush=True)
            continue
        text = daten.decode('utf-8', errors='replace').replace('\r', ' ').replace('\n', ' ').strip()
        ziel.schreibe(f'{datetime.now():%Y-%m-%d %H:%M:%S}\t{ip}\t{text}')


def test(k):
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.sendto(b'<14>netmon_syslog Probemeldung', ('127.0.0.1', k['port']))
    print(f'Probemeldung an 127.0.0.1:{k["port"]} geschickt — sie sollte in {k["log_dir"]} stehen '
          '(nur wenn 127.0.0.1 erlaubt ist oder erlaubt leer ist).')
    return 0


def main():
    ap = argparse.ArgumentParser(description='Syslog-Empfang des Netzwerk-Moduls')
    ap.add_argument('--test', action='store_true', help='Probemeldung an den eigenen Port schicken')
    args = ap.parse_args()
    k = lies_konfig()
    return test(k) if args.test else empfang(k)


if __name__ == '__main__':
    sys.exit(main())
