<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;

/** Zustand eines knotenlosen Alarms (z. B. WLAN-Andrang je SSID) aus dem letzten Lauf. */
class AlarmZustand extends Model
{
    protected $table = 'netzwerk_alarm_zustand';

    protected $fillable = ['schluessel', 'wert'];

    protected function casts(): array
    {
        return ['wert' => 'array'];
    }

    /** @return array<string, mixed> */
    public static function lesen(string $schluessel): array
    {
        return (array) (static::where('schluessel', $schluessel)->value('wert') ?? []);
    }

    /** @param array<string, mixed> $wert */
    public static function schreiben(string $schluessel, array $wert): void
    {
        static::updateOrCreate(['schluessel' => $schluessel], ['wert' => $wert]);
    }
}
