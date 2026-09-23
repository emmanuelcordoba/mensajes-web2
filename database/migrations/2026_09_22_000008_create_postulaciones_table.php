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
        Schema::create('postulaciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombres', 60);
            $table->string('apellidos', 60);
            $table->integer('dni')->unique();
            $table->date('fecha_nacimiento');
            $table->string('direccion');
            $table->string('telefono', 20);
            $table->string('email')->unique();
            $table->char('tipo_vehiculo', 1);
            $table->string('pregunta_tiene_celular', 2)->nullable();
            $table->string('pregunta_tiene_datos', 2)->nullable();
            $table->string('pregunta_experiencia_app', 2)->nullable();
            $table->string('pregunta_equipo_en_condiciones', 2)->nullable();
            $table->string('recomendado_por')->nullable();
            $table->foreignId('cadete_id')->nullable()->index()->constrained('cadetes');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE postulaciones ADD CONSTRAINT postulaciones_email_normalizado_check CHECK (email = lower(btrim(email)))');
        DB::statement("ALTER TABLE postulaciones ADD CONSTRAINT postulaciones_tipo_vehiculo_check CHECK (tipo_vehiculo IN ('B', 'M'))");
        DB::statement('CREATE INDEX idx_postulaciones_pendientes ON postulaciones (cadete_id) WHERE cadete_id IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postulaciones');
    }
};
