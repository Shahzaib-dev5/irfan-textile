<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textile_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 200);
            $table->string('item_type', 30);
            $table->foreignId('category_id')->constrained('item_categories')->restrictOnDelete();
            $table->string('fabric_type', 100)->nullable();
            $table->timestamps();
        });
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('textile_definition_id')->nullable()->constrained('textile_definitions')->restrictOnDelete();
            $table->unique(['textile_definition_id', 'base_unit_id'], 'uq_textile_measurement');
        });
        Schema::table('fabric_specifications', fn (Blueprint $table) => $table->string('composition', 200)->nullable()->change());
        Schema::create('direct_purchase_confirmations', function (Blueprint $table) {
            $table->uuid('request_key')->primary();
            $table->char('payload_hash', 64);
            $table->foreignId('receipt_document_id')->constrained('documents')->restrictOnDelete();
            $table->foreignId('bill_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('payment_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('receipt_measurements', function (Blueprint $table) {
            $table->foreignId('goods_receipt_line_id')->primary()->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignId('rate_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('rate_quantity', 20, 6);
            $table->decimal('quoted_rate', 20, 8);
            $table->unsignedInteger('thaan_count')->nullable();
            $table->unsignedInteger('set_count')->nullable();
            $table->decimal('measured_meters', 20, 6)->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE receipt_measurements ADD CONSTRAINT chk_receipt_measurements_positive CHECK (rate_quantity > 0 AND quoted_rate > 0 AND (thaan_count IS NULL OR thaan_count > 0) AND (set_count IS NULL OR set_count > 0) AND (measured_meters IS NULL OR measured_meters > 0))');
        if (! DB::table('units')->where('code', 'THAAN')->exists()) {
            DB::table('units')->insert(['code' => 'THAAN', 'name' => 'Thaan', 'dimension' => 'count', 'decimal_places' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_measurements');
        Schema::dropIfExists('direct_purchase_confirmations');
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique('uq_textile_measurement');
            $table->dropConstrainedForeignId('textile_definition_id');
        });
        Schema::dropIfExists('textile_definitions');
        // Keep the referenced THAAN unit and nullable composition: rollback must not destroy data.
    }
};
