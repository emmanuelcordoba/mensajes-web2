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
     * The image is a file and this is its path, not its contents (PERF-2).
     */
    public function up(): void
    {
        Schema::create('publicidad_app_imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicidad_id')->unique()->constrained('publicidades_app')->cascadeOnDelete();
            $table->string('ruta_archivo')->unique();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE publicidad_app_imagenes ADD CONSTRAINT publicidad_app_imagenes_ruta_relativa_check CHECK ('.RutaDeArchivo::CHECK.')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publicidad_app_imagenes');
    }
};
