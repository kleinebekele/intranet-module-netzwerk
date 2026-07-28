<?php

namespace Intranet\Modules\Netzwerk\Support;

use Closure;
use Intranet\Modules\Netzwerk\Models\Geraet;
use Intranet\Modules\Netzwerk\Models\Geraetetyp;
use Throwable;

/**
 * Nachschlagen der Pflege-Daten (Typ/Standort/Info) zu Inventar-Geräten.
 *
 * Eine Abfrage für die ganze Seite statt eine je Zeile; der Schlüssel ist die
 * MAC (bevorzugt), ersatzweise die IP. Läuft die Migration erst nach dem
 * Composer-Update, fehlen die Tabellen noch — dann liefert das Nachschlagen
 * schlicht nichts, und die Seiten bleiben benutzbar.
 */
class GeraeteMeta
{
    /** @return Closure(?string, ?string): ?Geraet fn($mac, $ip) => Pflege-Zeile */
    public static function nachschlagen(): Closure
    {
        try {
            $alle = Geraet::with(['typ', 'standort'])->get();
        } catch (Throwable) {
            return fn () => null;
        }

        $nachMac = [];
        $nachIp = [];
        foreach ($alle as $zeile) {
            if ($zeile->mac !== null) {
                $nachMac[mb_strtolower($zeile->mac)] = $zeile;
            } elseif ($zeile->ip !== null) {
                $nachIp[$zeile->ip] = $zeile;
            }
        }

        return function (?string $mac, ?string $ip) use ($nachMac, $nachIp): ?Geraet {
            if ($mac !== null && isset($nachMac[mb_strtolower($mac)])) {
                return $nachMac[mb_strtolower($mac)];
            }

            return $ip !== null ? ($nachIp[$ip] ?? null) : null;
        };
    }

    /**
     * Anzeige-Paket für eine Zeile/einen Kopf: gepflegter Typ gewinnt, sonst
     * der erkannte (nur wenn es ihn im CRUD gibt — sonst wäre er im Formular
     * nicht wählbar und stiftete Verwirrung).
     *
     * @param  list<string>  $typNamen  vorhandene Typ-Namen (lowercase)
     * @return object{typ: ?string, erkannt: bool, standort: ?string, info: ?string}
     */
    public static function anzeige(?Geraet $pflege, ?string $erkannterTyp, array $typNamen): object
    {
        $typ = $pflege?->typ?->name;
        $erkannt = false;
        if ($typ === null && $erkannterTyp !== null
                && in_array(mb_strtolower($erkannterTyp), $typNamen, true)) {
            $typ = $erkannterTyp;
            $erkannt = true;
        }

        return (object) [
            'typ' => $typ,
            'erkannt' => $erkannt,
            'standort' => $pflege?->standort?->bezeichnung(),
            'info' => $pflege?->info,
        ];
    }

    /** @return list<string> Namen aller Gerätetypen, lowercase (leer ohne Tabelle). */
    public static function typNamen(): array
    {
        try {
            return Geraetetyp::pluck('name')
                ->map(fn ($name) => mb_strtolower($name))
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
