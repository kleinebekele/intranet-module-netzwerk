<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ein per CRUD gepflegter Standort: Gebäude, optional Stockwerk und Raum. */
class Standort extends Model
{
    protected $table = 'netzwerk_standorte';

    protected $fillable = ['gebaeude', 'stockwerk', 'raum'];

    public function geraete(): HasMany
    {
        return $this->hasMany(Geraet::class, 'standort_id');
    }

    /** Anzeige in einer Zeile: "Hauptgebäude · 1. OG · Raum 12". */
    public function bezeichnung(): string
    {
        return implode(' · ', array_filter([$this->gebaeude, $this->stockwerk, $this->raum]));
    }
}
