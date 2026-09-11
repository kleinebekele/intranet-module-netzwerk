<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kleines Gedächtnis für Alarme, die keinen Knoten betreffen (z. B. der
 * WLAN-Andrang je SSID): je Schlüssel ein JSON-Zustand des letzten Laufs,
 * damit nur der ÜBERGANG gemeldet wird und nicht alle zehn Minuten erneut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netzwerk_alarm_zustand', function (Blueprint $table) {
            $table->id();
            $table->string('schluessel', 120)->unique();
            $table->json('wert')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('netzwerk_alarm_zustand');
    }
};
