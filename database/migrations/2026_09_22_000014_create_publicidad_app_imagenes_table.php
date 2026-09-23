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
        Schema::create('publicidad_app_imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicidad_id')->unique()->constrained('publicidades_app')->cascadeOnDelete();
            $table->text('contenido_base64');
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publicidad_app_imagenes');
    }
};
