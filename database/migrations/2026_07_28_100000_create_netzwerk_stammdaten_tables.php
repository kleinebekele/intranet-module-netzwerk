<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pflege-Stammdaten des Netzwerk-Moduls: Gerätetypen und Standorte (je per
 * CRUD verwaltet) plus die Zuordnung je Gerät (Typ, Standort, freies
 * Info-Feld).
 *
 * Eigene Tabellen in der Instanz-DB — die MSSQL-Quelle des Collectors bleibt
 * read-only. Verknüpft wird über die MAC (stabilste Kennung), ersatzweise die
 * IP; ein echter FK in die Fremd-DB ist nicht möglich (Insel-Prinzip).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netzwerk_geraetetypen', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('netzwerk_standorte', function (Blueprint $table) {
            $table->id();
            $table->string('gebaeude');
            $table->string('stockwerk')->nullable();
            $table->string('raum')->nullable();
            $table->timestamps();
        });

        Schema::create('netzwerk_geraete', function (Blueprint $table) {
            $table->id();
            $table->string('mac', 20)->nullable()->unique();
            $table->string('ip', 45)->nullable()->index();
            $table->foreignId('geraetetyp_id')->nullable()
                ->constrained('netzwerk_geraetetypen')->nullOnDelete();
            $table->foreignId('standort_id')->nullable()
                ->constrained('netzwerk_standorte')->nullOnDelete();
            $table->text('info')->nullable();
            $table->timestamps();
        });

        // Grundausstattung an Typen — über das CRUD erweiterbar. Die Namen
        // kennt auch die automatische Erkennung (TypErkennung): wird einer
        // gelöscht, schlägt sie ihn schlicht nicht mehr vor.
        DB::table('netzwerk_geraetetypen')->insert(
            collect(['Switch', 'Access Point', 'WLAN-Controller', 'Firewall',
                'Server', 'PC', 'Laptop', 'Tablet', 'Smartphone', 'Drucker',
                'Telefon', 'Kamera'])
                ->map(fn ($name) => ['name' => $name, 'created_at' => now(), 'updated_at' => now()])
                ->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('netzwerk_geraete');
        Schema::dropIfExists('netzwerk_standorte');
        Schema::dropIfExists('netzwerk_geraetetypen');
    }
};
