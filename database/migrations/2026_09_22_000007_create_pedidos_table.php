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
     * The order number comes from its own sequence and is not unique yet: that
     * depends on DATA-1. After the data migration the sequence is moved to the real
     * maximum with setval() (see ESQUEMA.sql).
     */
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->integer('numero')->nullable()->index();
            $table->string('direccion')->nullable();
            $table->string('destino')->nullable();
            $table->text('detalle')->nullable();
            $table->string('responsable')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->decimal('valor', 12, 2)->nullable();
            $table->decimal('garantia', 12, 2)->nullable();
            $table->string('tipo_paquete')->nullable();
            $table->string('peso_paquete')->nullable();
            $table->decimal('valor_declarado', 12, 2)->nullable();
            $table->string('plataforma_origen', 10);
            $table->string('estado', 20)->index();
            $table->boolean('gastronomia')->default(false);
            $table->boolean('retorno_origen')->default(false);
            $table->foreignId('cliente_id')->nullable()->index()->constrained('clientes');
            $table->string('nombre_cliente')->nullable();
            $table->foreignId('cadete_id')->nullable()->index()->constrained('cadetes');
            $table->foreignId('user_id')->nullable()->index()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE pedidos ADD CONSTRAINT pedidos_plataforma_origen_check CHECK (plataforma_origen IN ('web', 'app', 'api'))");
        DB::statement("ALTER TABLE pedidos ADD CONSTRAINT pedidos_estado_check CHECK (estado IN ('Sin asignar', 'Cotizado', 'Aceptado', 'Asignado', 'En curso', 'Rechazado', 'Cancelado', 'Finalizado'))");
        DB::statement('CREATE INDEX idx_pedidos_created_at ON pedidos (created_at DESC)');

        DB::statement('CREATE SEQUENCE pedidos_numero_seq AS INTEGER START WITH 1 OWNED BY pedidos.numero');
        DB::statement("ALTER TABLE pedidos ALTER COLUMN numero SET DEFAULT nextval('pedidos_numero_seq')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
