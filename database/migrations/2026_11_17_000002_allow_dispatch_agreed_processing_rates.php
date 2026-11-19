<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_job_steps', fn (Blueprint $table) => $table->unsignedBigInteger('vendor_service_rate_id')->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('processing_job_steps')->whereNull('vendor_service_rate_id')->exists()) {
            throw new RuntimeException('Dispatch-agreed rate history exists; do not discard it during rollback.');
        }
        Schema::table('processing_job_steps', fn (Blueprint $table) => $table->unsignedBigInteger('vendor_service_rate_id')->nullable(false)->change());
    }
};
