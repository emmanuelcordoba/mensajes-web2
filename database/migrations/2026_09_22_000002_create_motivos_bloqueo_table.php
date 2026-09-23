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
     * A lookup table and not a CHECK: the reason is data with its own description,
     * and a foreign key rejects values the code invents (PANEL-12).
     */
    public function up(): void
    {
        Schema::create('motivos_bloqueo', function (Blueprint $table) {
            $table->string('codigo', 30)->primary();
            $table->string('descripcion', 120);
        });

        DB::table('motivos_bloqueo')->insert([
            ['codigo' => 'falta-de-pago', 'descripcion' => 'Bloqueado por falta de pago.'],
            ['codigo' => 'reclamo-pedido', 'descripcion' => 'Bloqueado por reclamo en un pedido.'],
            ['codigo' => 'otro-motivo', 'descripcion' => 'Bloqueado por otro motivo.'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('motivos_bloqueo');
    }
};
