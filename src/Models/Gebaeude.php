<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Oberste Standort-Ebene. Löschen räumt Stockwerke und Räume mit ab. */
class Gebaeude extends Model
{
    protected $table = 'netzwerk_gebaeude';

    protected $fillable = ['name'];

    public function stockwerke(): HasMany
    {
        return $this->hasMany(Stockwerk::class, 'gebaeude_id');
    }

    public function raeume(): HasMany
    {
        return $this->hasMany(Raum::class, 'gebaeude_id');
    }

    public function geraete(): HasMany
    {
        return $this->hasMany(Geraet::class, 'gebaeude_id');
    }
}
