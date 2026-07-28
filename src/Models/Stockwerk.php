<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stockwerk eines Gebäudes. Löschen räumt seine Räume mit ab. */
class Stockwerk extends Model
{
    protected $table = 'netzwerk_stockwerke';

    protected $fillable = ['gebaeude_id', 'name'];

    public function gebaeude(): BelongsTo
    {
        return $this->belongsTo(Gebaeude::class, 'gebaeude_id');
    }

    public function raeume(): HasMany
    {
        return $this->hasMany(Raum::class, 'stockwerk_id');
    }

    public function geraete(): HasMany
    {
        return $this->hasMany(Geraet::class, 'stockwerk_id');
    }
}
