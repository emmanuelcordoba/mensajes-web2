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
     * "user_id" has no foreign key on purpose: a report can come from a closed
     * session or a deleted user, and losing it would be worse than keeping it.
     */
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->text('message')->nullable();
            $table->string('source', 30)->nullable();
            $table->string('context')->nullable();
            $table->text('extra')->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->string('user_email')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE INDEX idx_error_logs_created_at ON error_logs (created_at DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
