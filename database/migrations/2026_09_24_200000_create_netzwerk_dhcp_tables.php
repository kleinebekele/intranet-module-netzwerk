<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DHCP-Seite. Die Werte schickt ein Skript auf dem DHCP-Server
 * (scripts/dhcp-statistik.ps1) an den Webhook-Eingang; der Task Netzwerk/Dhcp
 * legt sie ab.
 *
 *  - netzwerk_dhcp_messwerte  = je Bereich und Messung die Zählwerte (Verlauf)
 *  - netzwerk_dhcp_bereiche   = je Bereich der letzte Stand: Grenzen, Ausschlüsse,
 *                               Reservierungen (JSON, wird jedes Mal ersetzt)
 *  - netzwerk_dhcp_belegungen = wer wann welche Adresse hatte: eine Zeile je
 *                               ununterbrochener Belegung (IP + MAC)
 *  - netzwerk_dhcp_manuell    = im Intranet von Hand belegte Adressen
 *
 * Die Seite lag in einer Vorversion in einem anderen Modul (Tabellen
 * `verwaltung_dhcp_*`). Gibt es diese noch, werden sie umbenannt statt neu
 * angelegt – die gesammelten Daten ziehen mit.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['messwerte', 'bereiche', 'belegungen', 'manuell'] as $teil) {
            $neu = 'netzwerk_dhcp_'.$teil;
            $alt = 'verwaltung_dhcp_'.$teil;
            if (! Schema::hasTable($neu) && Schema::hasTable($alt)) {
                Schema::rename($alt, $neu);
            }
        }

        if (! Schema::hasTable('netzwerk_dhcp_messwerte')) {
            Schema::create('netzwerk_dhcp_messwerte', function (Blueprint $table): void {
                $table->id();
                $table->dateTime('gemessen_am');
                $table->string('scope', 64);
                $table->unsignedInteger('frei')->default(0);
                $table->unsignedInteger('belegt')->default(0);
                $table->unsignedInteger('reserviert')->default(0);
                $table->decimal('auslastung', 5, 1)->default(0);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['scope', 'gemessen_am']);
                $table->index('gemessen_am');
            });
        }

        if (! Schema::hasTable('netzwerk_dhcp_bereiche')) {
            Schema::create('netzwerk_dhcp_bereiche', function (Blueprint $table): void {
                $table->id();
                $table->string('scope', 64)->unique();
                $table->string('name', 255)->nullable();
                $table->string('server', 255)->nullable();
                $table->string('maske', 64)->nullable();
                $table->string('von', 64)->nullable();
                $table->string('bis', 64)->nullable();
                $table->unsignedInteger('lease_minuten')->nullable();
                $table->json('ausschluesse')->nullable();
                $table->json('reservierungen')->nullable();
                $table->dateTime('gemessen_am')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('netzwerk_dhcp_belegungen')) {
            Schema::create('netzwerk_dhcp_belegungen', function (Blueprint $table): void {
                $table->id();
                $table->string('scope', 64);
                $table->string('ip', 64);
                $table->string('mac', 64);
                $table->string('geraet', 255)->nullable();
                $table->string('status', 64)->nullable();
                $table->boolean('reserviert')->default(false);
                $table->dateTime('ablauf')->nullable();
                $table->dateTime('erstmals');
                $table->dateTime('zuletzt');

                $table->index(['scope', 'zuletzt']);
                $table->index(['ip', 'mac', 'zuletzt']);
                $table->index('mac');
            });
        }

        if (! Schema::hasTable('netzwerk_dhcp_manuell')) {
            Schema::create('netzwerk_dhcp_manuell', function (Blueprint $table): void {
                $table->id();
                $table->string('scope', 64);
                $table->string('ip', 64);
                $table->string('bezeichnung', 255);
                $table->text('notiz')->nullable();
                $table->unsignedBigInteger('geaendert_von')->nullable();
                $table->timestamps();

                $table->unique(['scope', 'ip']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('netzwerk_dhcp_manuell');
        Schema::dropIfExists('netzwerk_dhcp_belegungen');
        Schema::dropIfExists('netzwerk_dhcp_bereiche');
        Schema::dropIfExists('netzwerk_dhcp_messwerte');
    }
};
