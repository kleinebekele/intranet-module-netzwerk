<?php

namespace Intranet\Modules\Netzwerk\Support;

/**
 * Automatische Gerätetyp-Erkennung aus dem, was der Collector ohnehin weiß.
 *
 * Liefert NAMEN aus dem Gerätetypen-CRUD (nicht IDs): Angezeigt bzw. im
 * Formular vorausgewählt wird ein erkannter Typ nur, wenn er dort (noch)
 * existiert. Erkanntes wird bewusst NICHT in die DB geschrieben — gespeichert
 * ist nur, was jemand im Formular bestätigt; die Erkennung bleibt dadurch
 * jederzeit verbesserbar, ohne Altlasten aufräumen zu müssen.
 */
class TypErkennung
{
    /** Infrastruktur-Knoten: die Art kennt der Collector zuverlässig. */
    public static function fuerKnoten(?string $art): ?string
    {
        return match ($art) {
            'switch' => 'Switch',
            'ap' => 'Access Point',
            'controller' => 'WLAN-Controller',
            'firewall' => 'Firewall',
            default => null,
        };
    }

    /**
     * Hostname-Indizien, der Reihe nach geprüft (das stärkste Signal: so hat
     * jemand das Gerät benannt). "iphone" MUSS vor "phone" stehen.
     */
    private const HOSTNAME_MUSTER = [
        'drucker' => 'Drucker', 'print' => 'Drucker', 'mfp' => 'Drucker',
        'kamera' => 'Kamera', 'cam' => 'Kamera',
        'iphone' => 'Smartphone', 'android' => 'Smartphone',
        'ipad' => 'Tablet', 'tablet' => 'Tablet',
        'macbook' => 'Laptop', 'laptop' => 'Laptop', 'notebook' => 'Laptop',
        'telefon' => 'Telefon', 'phone' => 'Telefon',
        'switch' => 'Switch', 'firewall' => 'Firewall', 'opnsense' => 'Firewall',
        'server' => 'Server', 'nas' => 'Server',
    ];

    /**
     * Hersteller-Indizien (nmap-OUI-Namen), als Rückfall nach dem Hostname.
     * Nur eindeutige Hersteller — Mainboard-Hersteller heißt Desktop-PC,
     * Apple & Co. bleiben bewusst draußen (zu mehrdeutig).
     */
    private const VENDOR_MUSTER = [
        'brother' => 'Drucker', 'lexmark' => 'Drucker', 'kyocera' => 'Drucker',
        'ricoh' => 'Drucker', 'epson' => 'Drucker', 'xerox' => 'Drucker',
        'canon' => 'Drucker', 'konica' => 'Drucker', 'utax' => 'Drucker',
        'zebra' => 'Drucker', 'hewlett' => 'Drucker', 'hp inc' => 'Drucker',
        'micro-star' => 'PC', 'asustek' => 'PC', 'gigabyte' => 'PC',
        'asrock' => 'PC', 'dell' => 'PC', 'fujitsu' => 'PC', 'medion' => 'PC',
        'raspberry' => 'Server', 'synology' => 'Server', 'qnap' => 'Server',
        'yealink' => 'Telefon', 'snom' => 'Telefon', 'gigaset' => 'Telefon',
        'grandstream' => 'Telefon',
        'axis' => 'Kamera', 'hikvision' => 'Kamera', 'dahua' => 'Kamera',
        'reolink' => 'Kamera',
        'netgear' => 'Switch',
    ];

    /** Endgeräte: Indizien aus Hostname und Hersteller. */
    public static function fuerGeraet(?string $hostname, ?string $vendor): ?string
    {
        $host = mb_strtolower((string) $hostname);
        foreach (self::HOSTNAME_MUSTER as $muster => $typ) {
            if ($host !== '' && str_contains($host, $muster)) {
                return $typ;
            }
        }

        $hersteller = mb_strtolower((string) $vendor);
        foreach (self::VENDOR_MUSTER as $muster => $typ) {
            if ($hersteller !== '' && str_contains($hersteller, $muster)) {
                return $typ;
            }
        }

        return null;
    }
}
