<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Raum — hängt an einem Stockwerk oder (stockwerk_id NULL) direkt am Gebäude. */
class Raum extends Model
{
    protected $table = 'netzwerk_raeume';

    protected $fillable = ['gebaeude_id', 'stockwerk_id', 'name'];

    public function gebaeude(): BelongsTo
    {
        return $this->belongsTo(Gebaeude::class, 'gebaeude_id');
    }

    public function stockwerk(): BelongsTo
    {
        return $this->belongsTo(Stockwerk::class, 'stockwerk_id');
    }

    public function geraete(): HasMany
    {
        return $this->hasMany(Geraet::class, 'raum_id');
    }
}
