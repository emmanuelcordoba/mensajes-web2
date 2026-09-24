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
     * The photo is a file and this is its path, not its contents: the 1,129 MB of
     * base64 in MongoDB are written to disk by the ETL (PERF-2). The unique
     * index keeps two rows from sharing a file, since deleting one would leave
     * the other pointing at nothing.
     */
    public function up(): void
    {
        Schema::create('user_fotos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('ruta_archivo')->unique();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE user_fotos ADD CONSTRAINT user_fotos_ruta_relativa_check CHECK ('.RutaDeArchivo::CHECK.')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_fotos');
    }
};
