<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein per Knopf ausgeblendeter Infrastruktur-Knoten (gehört nicht dazu, z. B.
 * ein Virtualisierungs-Host, dessen Bridge LLDP spricht). Weg von Karte,
 * Detailseite und Alarm; die MSSQL-Daten des Collectors bleiben unberührt,
 * Wieder-Einblenden ist jederzeit möglich.
 */
class AusgeblendeterKnoten extends Model
{
    protected $table = 'netzwerk_ausgeblendete_knoten';

    protected $fillable = ['matchkey', 'name', 'ip', 'art'];

    /**
     * Schlüssel eines Knotens aus den Collector-Rohdaten: der matchKey
     * (Chassis-MAC bzw. ip:<ip>), bei Demo-Daten ohne matchKey die Id.
     */
    public static function schluessel(object $knoten): string
    {
        $matchKey = mb_strtolower(trim((string) ($knoten->matchKey ?? '')));

        return $matchKey !== '' ? $matchKey : 'demo:'.(int) ($knoten->id ?? 0);
    }

    /** @return array<string, true> Menge der ausgeblendeten Schlüssel */
    public static function schluesselMenge(): array
    {
        return array_fill_keys(static::query()->pluck('matchkey')->all(), true);
    }
}
