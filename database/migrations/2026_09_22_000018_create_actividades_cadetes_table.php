<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An activity without "fin" is not necessarily open: 112,779 migrated ones were
     * never closed (PANEL-17). The partial index finds the open one of a cadete.
     */
    public function up(): void
    {
        Schema::create('actividades_cadetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cadete_id')->index()->constrained('cadetes')->cascadeOnDelete();
            $table->timestampTz('inicio');
            $table->timestampTz('fin')->nullable();
            $table->integer('duracion')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE INDEX idx_actividad_sin_fin ON actividades_cadetes (cadete_id, inicio DESC) WHERE fin IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actividades_cadetes');
    }
};
