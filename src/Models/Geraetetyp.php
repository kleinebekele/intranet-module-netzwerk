<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ein per CRUD gepflegter Gerätetyp (Switch, Drucker, PC, …). */
class Geraetetyp extends Model
{
    protected $table = 'netzwerk_geraetetypen';

    protected $fillable = ['name'];

    public function geraete(): HasMany
    {
        return $this->hasMany(Geraet::class, 'geraetetyp_id');
    }
}
