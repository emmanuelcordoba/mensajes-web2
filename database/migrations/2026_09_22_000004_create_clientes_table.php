<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The name shown everywhere is generated, never written. See ESQUEMA.sql: the
     * panel writes nombre, the app writes nombres/apellidos, and neither one can
     * overwrite the other. regexp_replace collapses inner spaces too, because a
     * single double space kept the panel from linking an order to its client
     * (DATA-8). CONCAT_WS is not usable here: PostgreSQL marks it STABLE, and a
     * generated column needs IMMUTABLE.
     */
    private const NOMBRE_MOSTRADO = "NULLIF(TRIM(regexp_replace(COALESCE(NULLIF(TRIM(nombre), ''), COALESCE(nombres, '') || ' ' || COALESCE(apellidos, '')), '\s+', ' ', 'g')), '')";

    /**
     * Run the migrations.
     *
     * The client number comes from its own sequence. After the data migration it is
     * moved to the real maximum with setval() (see ESQUEMA.sql).
     */
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->integer('numero')->unique();
            $table->string('nombre')->nullable();
            $table->string('nombres', 60)->nullable();
            $table->string('apellidos', 60)->nullable();
            $table->string('nombre_mostrado')->storedAs(self::NOMBRE_MOSTRADO)->index();
            $table->string('nombre_empleado')->nullable();
            $table->string('direccion');
            $table->string('telefono', 20);
            $table->string('plataforma', 10)->nullable();
            $table->text('fcm_token')->nullable();
            $table->foreignId('user_id')->nullable()->index()->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE SEQUENCE clientes_numero_seq AS INTEGER START WITH 1 OWNED BY clientes.numero');
        DB::statement("ALTER TABLE clientes ALTER COLUMN numero SET DEFAULT nextval('clientes_numero_seq')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
