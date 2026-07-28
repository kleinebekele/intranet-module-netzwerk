<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gedächtnis des Alarm-Tasks (Ekkon-Kür): je Infrastruktur-Knoten der zuletzt
 * gesehene Zustand — nur aus dem Vergleich zweier Läufe entstehen Übergänge
 * („war online, ist es nicht mehr") statt Dauerfeuer. Schlüssel ist der
 * matchKey des Collectors (Chassis-MAC bzw. ip:<ip>), der Umbenennungen und
 * Neuanlagen in der MSSQL überlebt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netzwerk_knoten_status', function (Blueprint $table) {
            $table->id();
            $table->string('matchkey', 64)->unique();
            $table->string('name')->nullable();
            $table->string('status', 20)->nullable();
            $table->boolean('online')->default(false);
            $table->timestamp('zuletzt_gesehen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netzwerk_knoten_status');
    }
};
