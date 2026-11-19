<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_job_steps', fn (Blueprint $t) => $t->boolean('rate_pending')->default(false));
        Schema::table('processing_receipt_lines', function (Blueprint $t) {
            $t->foreignId('billing_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $t->decimal('unit_rate_pkr', 20, 8)->nullable();
        });
        DB::statement('ALTER TABLE processing_receipt_lines ADD CONSTRAINT chk_receipt_actual_rate CHECK ((unit_rate_pkr IS NULL AND billing_unit_id IS NULL) OR (unit_rate_pkr IS NOT NULL AND billing_unit_id IS NOT NULL AND unit_rate_pkr >= 0))');
        Schema::create('fabric_measurement_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_item_id')->constrained('items')->restrictOnDelete();
            $t->foreignId('output_item_id')->unique()->constrained('items')->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $t->unique(['source_item_id', 'unit_id']);
        });
        Schema::create('fabric_cuttings', function (Blueprint $t) {
            $t->foreignId('document_id')->primary()->constrained('documents')->restrictOnDelete();
            $t->foreignId('source_stock_position_id')->constrained('stock_positions')->restrictOnDelete();
            $t->foreignId('output_stock_position_id')->constrained('stock_positions')->restrictOnDelete();
            foreach (['source_quantity', 'output_quantity', 'retained_meters', 'normal_loss', 'abnormal_loss'] as $field) {
                $t->decimal($field, 20, 6);
            }
            $t->decimal('input_cost_pkr', 20, 4);
            $t->decimal('abnormal_cost_pkr', 20, 4);
            $t->string('evidence', 1000);
        });
        DB::statement('ALTER TABLE fabric_cuttings ADD CONSTRAINT chk_fabric_cutting_quantities CHECK (source_stock_position_id <> output_stock_position_id AND source_quantity > 0 AND output_quantity = FLOOR(output_quantity) AND output_quantity > 0 AND retained_meters > 0 AND normal_loss >= 0 AND abnormal_loss >= 0 AND source_quantity = retained_meters + normal_loss + abnormal_loss AND input_cost_pkr >= 0 AND abnormal_cost_pkr >= 0)');
        DB::table('document_types')->insert(['code' => 'fabric_cutting', 'name' => 'Fabric cutting', 'module_code' => 'inventory', 'prefix' => 'CUT', 'numbering_width' => 6, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        throw new LogicException('Forward-only conversion history migration; restore an approved backup.');
    }
};
