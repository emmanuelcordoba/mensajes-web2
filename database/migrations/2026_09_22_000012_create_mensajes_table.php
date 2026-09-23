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
        Schema::create('mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cadete_id')->index()->constrained('cadetes');
            $table->foreignId('user_id')->index()->constrained('users');
            $table->text('mensaje')->nullable();
            $table->boolean('leido_web')->default(false);
            $table->boolean('leido_app')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('CREATE INDEX idx_mensajes_no_leidos_web ON mensajes (cadete_id) WHERE leido_web = FALSE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mensajes');
    }
};
