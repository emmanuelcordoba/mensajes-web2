<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('montos_semanales', function (Blueprint $table) {
            $table->id();
            $table->char('tipo_vehiculo', 1)->unique();
            $table->decimal('monto', 12, 2);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE montos_semanales ADD CONSTRAINT montos_semanales_tipo_vehiculo_check CHECK (tipo_vehiculo IN ('B', 'M'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('montos_semanales');
    }
};
