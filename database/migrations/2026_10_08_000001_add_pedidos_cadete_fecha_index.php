<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The index the cadete's history screen needs: his orders, by date.
     *
     * Measured on the cadete with the most trips (11,966 finished orders, 903,489 in the
     * table). The six-month GROUP BY was walking `idx_pedidos_created_at` — every cadete's
     * orders in that range — and discarding 37,550 rows to keep 874. That cost grows with
     * the company's volume, not with the cadete's: in a year the same six months is a
     * bigger scan for everyone who opens the screen.
     *
     * `cadete_id` first because it is the equality, `created_at` second because it is the
     * range: the existing single-column indexes cannot serve both.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX idx_pedidos_cadete_fecha ON pedidos (cadete_id, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_pedidos_cadete_fecha');
    }
};
