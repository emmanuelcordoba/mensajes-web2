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
        Schema::create('postulacion_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->text('contenido_base64');
            $table->timestampsTz();

            $table->unique(['postulacion_id', 'tipo']);
        });

        DB::statement("ALTER TABLE postulacion_documentos ADD CONSTRAINT postulacion_documentos_tipo_check CHECK (tipo IN ('foto', 'dni_frente', 'dni_dorso', 'boleta_de_servicio'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postulacion_documentos');
    }
};
