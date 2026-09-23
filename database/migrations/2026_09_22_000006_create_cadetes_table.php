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
        Schema::create('cadetes', function (Blueprint $table) {
            $table->id();
            $table->integer('numero_movil')->unique();
            $table->string('apellidos', 60);
            $table->string('nombres', 60);
            $table->string('direccion');
            $table->string('telefono', 20);
            $table->date('fecha_nacimiento');
            $table->integer('dni')->nullable()->unique();
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->index();
            $table->char('tipo_vehiculo', 1);
            $table->double('ubicacion_lat')->nullable();
            $table->double('ubicacion_lon')->nullable();
            $table->text('fcm_token')->nullable();
            $table->bigInteger('orden_cola')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_vencimiento')->nullable()->index();
            $table->decimal('monto_semanal', 12, 2)->nullable();
            $table->decimal('monto_deuda', 12, 2)->nullable();
            $table->decimal('monto_pagado_efectivo', 12, 2)->nullable();
            $table->decimal('monto_pagado_tickets', 12, 2)->nullable();
            $table->decimal('cobranza_saldo', 12, 2)->nullable();
            $table->boolean('tiene_monto_semanal_personal')->default(false);
            $table->string('modalidad_cobranza', 10)->default('Semanal');
            $table->foreignId('user_id')->index()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE cadetes ADD CONSTRAINT cadetes_estado_check CHECK (estado IN ('activo-app', 'activo-web', 'inactivo', 'con-pedido-app', 'postulado'))");
        DB::statement("ALTER TABLE cadetes ADD CONSTRAINT cadetes_tipo_vehiculo_check CHECK (tipo_vehiculo IN ('B', 'M'))");
        DB::statement("ALTER TABLE cadetes ADD CONSTRAINT cadetes_modalidad_cobranza_check CHECK (modalidad_cobranza IN ('Semanal', 'Saldo'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cadetes');
    }
};
