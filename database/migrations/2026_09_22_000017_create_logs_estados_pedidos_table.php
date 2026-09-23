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
        Schema::create('logs_estados_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->index()->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('cadete_id')->nullable()->index()->constrained('cadetes');
            $table->string('estado', 20);
            $table->string('mensaje')->nullable();
            $table->string('plataforma_origen', 12);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE logs_estados_pedidos ADD CONSTRAINT logs_estado_check CHECK (estado IN ('Sin asignar', 'Cotizado', 'Aceptado', 'Asignado', 'En curso', 'Rechazado', 'Cancelado', 'Finalizado'))");
        DB::statement("ALTER TABLE logs_estados_pedidos ADD CONSTRAINT logs_plataforma_check CHECK (plataforma_origen IN ('web', 'api', 'cliente-app', 'cadete-app'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_estados_pedidos');
    }
};
