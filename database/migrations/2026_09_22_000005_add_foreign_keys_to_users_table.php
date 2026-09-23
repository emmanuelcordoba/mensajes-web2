<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('rol_id')->references('id')->on('roles');
            $table->foreign('bloqueado')->references('codigo')->on('motivos_bloqueo');
            $table->foreign('cliente_restringido_id')->references('id')->on('clientes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['rol_id']);
            $table->dropForeign(['bloqueado']);
            $table->dropForeign(['cliente_restringido_id']);
        });
    }
};
