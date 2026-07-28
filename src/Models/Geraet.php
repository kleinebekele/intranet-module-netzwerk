<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pflege-Daten zu einem Gerät aus dem Inventar (Typ, Standort, Info).
 *
 * Es gibt nur dann eine Zeile, wenn jemand etwas hinterlegt hat. Verknüpft
 * wird lose über die MAC (bevorzugt, sie überlebt IP-Wechsel), ersatzweise
 * über die IP — das Inventar selbst liegt in der fremden MSSQL.
 *
 * Der Standort ist NORMALISIERT gespeichert: Wer einen Raum wählt, bekommt
 * auch dessen Stockwerk und Gebäude gesetzt — so zählen Gebäude/Stockwerke
 * ihre Geräte inklusive der Räume über einen simplen FK-Count.
 */
class Geraet extends Model
{
    protected $table = 'netzwerk_geraete';

    protected $fillable = ['mac', 'ip', 'geraetetyp_id', 'gebaeude_id', 'stockwerk_id', 'raum_id', 'info'];

    public function typ(): BelongsTo
    {
        return $this->belongsTo(Geraetetyp::class, 'geraetetyp_id');
    }

    public function gebaeude(): BelongsTo
    {
        return $this->belongsTo(Gebaeude::class, 'gebaeude_id');
    }

    public function stockwerk(): BelongsTo
    {
        return $this->belongsTo(Stockwerk::class, 'stockwerk_id');
    }

    public function raum(): BelongsTo
    {
        return $this->belongsTo(Raum::class, 'raum_id');
    }

    /** Anzeige in einer Zeile: "Hauptgebäude · 1. OG · Raum 12" (null = keiner). */
    public function standortBezeichnung(): ?string
    {
        $teile = array_filter([
            $this->gebaeude?->name,
            $this->stockwerk?->name,
            $this->raum?->name,
        ]);

        return $teile === [] ? null : implode(' · ', $teile);
    }
}
