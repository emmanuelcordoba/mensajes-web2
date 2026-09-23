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
     * "valor" stays as text: it is a generic key-value table and "tipo" says how to
     * read it. The ones of type "numero" must hold a non-negative number (PANEL-16).
     */
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50)->unique();
            $table->string('titulo', 120);
            $table->string('descripcion')->nullable();
            $table->string('tipo', 10);
            $table->text('valor')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE configuraciones ADD CONSTRAINT configuraciones_tipo_check CHECK (tipo IN ('numero', 'texto'))");
        DB::statement("ALTER TABLE configuraciones ADD CONSTRAINT configuraciones_valor_numerico_check CHECK (tipo <> 'numero' OR valor ~ '^[0-9]+(\.[0-9]+)?$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
