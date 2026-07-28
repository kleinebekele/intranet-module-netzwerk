<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standorte als echte Hierarchie: Gebäude -> Stockwerke -> Räume, jede Ebene
 * einzeln pflegbar (statt je Raum das Gebäude neu zu tippen). Ein Raum kann
 * auch direkt am Gebäude hängen (stockwerk_id NULL).
 *
 * Geräte referenzieren alle drei Ebenen (normalisiert: ein Raum setzt auch
 * Stockwerk und Gebäude) — so zählt ein Gebäude seine Geräte inklusive aller
 * Räume über einen simplen FK-Count.
 *
 * Bestehende Daten (flache netzwerk_standorte + Zuordnungen) werden
 * übernommen, nichts geht verloren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('netzwerk_gebaeude', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('netzwerk_stockwerke', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gebaeude_id')->constrained('netzwerk_gebaeude')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['gebaeude_id', 'name']);
        });

        Schema::create('netzwerk_raeume', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gebaeude_id')->constrained('netzwerk_gebaeude')->cascadeOnDelete();
            $table->foreignId('stockwerk_id')->nullable()->constrained('netzwerk_stockwerke')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('netzwerk_geraete', function (Blueprint $table) {
            $table->foreignId('gebaeude_id')->nullable()
                ->constrained('netzwerk_gebaeude')->nullOnDelete();
            $table->foreignId('stockwerk_id')->nullable()
                ->constrained('netzwerk_stockwerke')->nullOnDelete();
            $table->foreignId('raum_id')->nullable()
                ->constrained('netzwerk_raeume')->nullOnDelete();
        });

        // ── Bestehende flache Standorte in die Hierarchie überführen ─────────
        $jetzt = now();
        $gebaeudeIds = [];      // Name -> id
        $stockwerkIds = [];     // "<gebId>|<Name>" -> id

        foreach (DB::table('netzwerk_standorte')->orderBy('id')->get() as $alt) {
            $gebaeudeIds[$alt->gebaeude] ??= DB::table('netzwerk_gebaeude')->insertGetId(
                ['name' => $alt->gebaeude, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
            $gebId = $gebaeudeIds[$alt->gebaeude];

            $stoId = null;
            if ($alt->stockwerk !== null && trim($alt->stockwerk) !== '') {
                $stockwerkIds[$gebId.'|'.$alt->stockwerk] ??= DB::table('netzwerk_stockwerke')->insertGetId(
                    ['gebaeude_id' => $gebId, 'name' => $alt->stockwerk,
                        'created_at' => $jetzt, 'updated_at' => $jetzt]);
                $stoId = $stockwerkIds[$gebId.'|'.$alt->stockwerk];
            }

            $raumId = null;
            if ($alt->raum !== null && trim($alt->raum) !== '') {
                $raumId = DB::table('netzwerk_raeume')->insertGetId(
                    ['gebaeude_id' => $gebId, 'stockwerk_id' => $stoId, 'name' => $alt->raum,
                        'created_at' => $jetzt, 'updated_at' => $jetzt]);
            }

            DB::table('netzwerk_geraete')->where('standort_id', $alt->id)->update([
                'gebaeude_id' => $gebId,
                'stockwerk_id' => $stoId,
                'raum_id' => $raumId,
            ]);
        }

        Schema::table('netzwerk_geraete', function (Blueprint $table) {
            $table->dropConstrainedForeignId('standort_id');
        });
        Schema::dropIfExists('netzwerk_standorte');
    }

    public function down(): void
    {
        Schema::create('netzwerk_standorte', function (Blueprint $table) {
            $table->id();
            $table->string('gebaeude');
            $table->string('stockwerk')->nullable();
            $table->string('raum')->nullable();
            $table->timestamps();
        });
        Schema::table('netzwerk_geraete', function (Blueprint $table) {
            $table->foreignId('standort_id')->nullable()
                ->constrained('netzwerk_standorte')->nullOnDelete();
        });

        // Rückweg (bestmöglich): je Gerät seine Kombination als flache Zeile.
        $jetzt = now();
        $kombiIds = [];
        $geraete = DB::table('netzwerk_geraete')
            ->leftJoin('netzwerk_gebaeude as g', 'g.id', '=', 'netzwerk_geraete.gebaeude_id')
            ->leftJoin('netzwerk_stockwerke as s', 's.id', '=', 'netzwerk_geraete.stockwerk_id')
            ->leftJoin('netzwerk_raeume as r', 'r.id', '=', 'netzwerk_geraete.raum_id')
            ->whereNotNull('netzwerk_geraete.gebaeude_id')
            ->get(['netzwerk_geraete.id', 'g.name as gebaeude', 's.name as stockwerk', 'r.name as raum']);
        foreach ($geraete as $g) {
            $schluessel = $g->gebaeude.'|'.$g->stockwerk.'|'.$g->raum;
            $kombiIds[$schluessel] ??= DB::table('netzwerk_standorte')->insertGetId(
                ['gebaeude' => $g->gebaeude, 'stockwerk' => $g->stockwerk, 'raum' => $g->raum,
                    'created_at' => $jetzt, 'updated_at' => $jetzt]);
            DB::table('netzwerk_geraete')->where('id', $g->id)
                ->update(['standort_id' => $kombiIds[$schluessel]]);
        }

        Schema::table('netzwerk_geraete', function (Blueprint $table) {
            $table->dropConstrainedForeignId('raum_id');
            $table->dropConstrainedForeignId('stockwerk_id');
            $table->dropConstrainedForeignId('gebaeude_id');
        });
        Schema::dropIfExists('netzwerk_raeume');
        Schema::dropIfExists('netzwerk_stockwerke');
        Schema::dropIfExists('netzwerk_gebaeude');
    }
};
