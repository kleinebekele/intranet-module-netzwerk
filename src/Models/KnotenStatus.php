<?php

namespace Intranet\Modules\Netzwerk\Models;

use Illuminate\Database\Eloquent\Model;

/** Zuletzt bekannter Zustand eines Infrastruktur-Knotens (für den Alarm-Task). */
class KnotenStatus extends Model
{
    protected $table = 'netzwerk_knoten_status';

    protected $fillable = ['matchkey', 'name', 'status', 'online', 'zuletzt_gesehen'];

    protected function casts(): array
    {
        return [
            'online' => 'boolean',
            'zuletzt_gesehen' => 'datetime',
        ];
    }
}
