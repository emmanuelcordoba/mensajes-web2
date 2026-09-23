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
     * No soft deletes: these are money records and are never deleted.
     * The partial unique index allows a single discount per order (COB-1).
     */
    public function up(): void
    {
        Schema::create('cobranza_saldo_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cadete_id')->constrained('cadetes');
            $table->decimal('monto', 12, 2);
            $table->boolean('monto_positivo');
            $table->decimal('saldo_parcial', 12, 2)->nullable();
            $table->string('tipo', 30);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE cobranza_saldo_movimientos ADD CONSTRAINT cobranza_movimientos_tipo_check CHECK (tipo IN ('Carga de saldo', 'Pedido finalizado'))");
        DB::statement('CREATE INDEX idx_cobranza_mov_created_at ON cobranza_saldo_movimientos (cadete_id, created_at DESC)');
        DB::statement("CREATE UNIQUE INDEX cobranza_mov_un_descuento_por_pedido ON cobranza_saldo_movimientos (pedido_id) WHERE tipo = 'Pedido finalizado'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cobranza_saldo_movimientos');
    }
};
