<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_dispatches MODIFY warehouse_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (DB::table('sales_dispatches')->whereNull('warehouse_id')->exists()) {
            throw new RuntimeException('Cannot require one dispatch warehouse while multi-warehouse dispatches exist.');
        }

        DB::statement('ALTER TABLE sales_dispatches MODIFY warehouse_id BIGINT UNSIGNED NOT NULL');
    }
};
