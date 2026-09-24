<?php

use App\Support\RutaDeArchivo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The four documents are files and this is their path, not their contents
     * (PERF-2). These are the most sensitive images of the three tables: two of
     * the four are the person's ID card, front and back, so the disk they live
     * on cannot be a public one.
     */
    public function up(): void
    {
        Schema::create('postulacion_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('ruta_archivo')->unique();
            $table->timestampsTz();

            $table->unique(['postulacion_id', 'tipo']);
        });

        DB::statement("ALTER TABLE postulacion_documentos ADD CONSTRAINT postulacion_documentos_tipo_check CHECK (tipo IN ('foto', 'dni_frente', 'dni_dorso', 'boleta_de_servicio'))");
        DB::statement('ALTER TABLE postulacion_documentos ADD CONSTRAINT postulacion_documentos_ruta_relativa_check CHECK ('.RutaDeArchivo::CHECK.')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postulacion_documentos');
    }
};
