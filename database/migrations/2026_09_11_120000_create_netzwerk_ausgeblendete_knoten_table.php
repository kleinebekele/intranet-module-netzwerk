<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knoten, die der Collector per LLDP findet, aber nicht zur überwachten
 * Infrastruktur gehören (z. B. ein Virtualisierungs-Host, dessen Bridge
 * LLDP spricht): per Knopf ausgeblendet — weg von Karte, Detailseite und
 * Alarm. Schlüssel ist der matchKey des Collectors, der Umbenennungen und
 * Neuanlagen in der MSSQL überlebt. Die MSSQL selbst bleibt unberührt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netzwerk_ausgeblendete_knoten', function (Blueprint $table) {
            $table->id();
            $table->string('matchkey', 64)->unique();
            $table->string('name')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('art', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netzwerk_ausgeblendete_knoten');
    }
};
