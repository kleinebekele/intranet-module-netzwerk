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
 */
class Geraet extends Model
{
    protected $table = 'netzwerk_geraete';

    protected $fillable = ['mac', 'ip', 'geraetetyp_id', 'standort_id', 'info'];

    public function typ(): BelongsTo
    {
        return $this->belongsTo(Geraetetyp::class, 'geraetetyp_id');
    }

    public function standort(): BelongsTo
    {
        return $this->belongsTo(Standort::class, 'standort_id');
    }
}
