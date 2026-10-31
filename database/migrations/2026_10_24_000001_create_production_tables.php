<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function fk(Blueprint $t, string $column, string $table, string $key = 'id', bool $nullable = false): void
    {
        $t->unsignedBigInteger($column)->nullable($nullable);
        $suffix = substr(sha1($t->getTable().'_'.$column), 0, 16);
        $t->index($column, 'idx_p9_'.$suffix);
        $t->foreign($column, 'fk_p9_'.$suffix)->references($key)->on($table)->restrictOnDelete()->restrictOnUpdate();
    }

    private function stamp(Blueprint $t): void
    {
        $t->engine = 'InnoDB';
        $t->dateTime('created_at', 6)->useCurrent();
        $t->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
    }

    private function header(Blueprint $t): void
    {
        $t->unsignedBigInteger('document_id')->primary();
        $t->foreign('document_id')->references('id')->on('documents')->restrictOnDelete()->restrictOnUpdate();
        $this->stamp($t);
    }

    private function line(Blueprint $t, string $parent): void
    {
        $t->id();
        $this->fk($t, 'document_id', $parent, 'document_id');
        $t->unsignedSmallInteger('line_no');
        $t->unique(['document_id', 'line_no']);
        $this->stamp($t);
    }

    public function up(): void
    {
        Schema::create('bills_of_materials', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'product_variant_id', 'product_variants');
            $t->unsignedInteger('version_number');
            $t->decimal('output_quantity', 20, 6)->default(1);
            $t->string('state', 20)->default('draft');
            $t->boolean('is_active')->default(true);
            $t->unique(['product_variant_id', 'version_number']);
            $t->index(['state', 'is_active']);
            $this->stamp($t);
        });
        Schema::create('bill_of_material_lines', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'bill_of_material_id', 'bills_of_materials');
            $t->unsignedSmallInteger('line_no');
            $this->fk($t, 'component_item_id', 'items');
            $t->string('component_name', 100);
            $t->decimal('net_quantity', 20, 6);
            $t->decimal('planned_wastage_fraction', 20, 10)->default(0);
            $t->unique(['bill_of_material_id', 'line_no']);
            $this->stamp($t);
        });
        Schema::create('manufacturing_batches', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'bill_of_material_id', 'bills_of_materials');
            $t->decimal('planned_output_quantity', 20, 6);
            $t->date('planned_start_on')->nullable();
            $t->date('planned_finish_on')->nullable();
            $t->string('instructions', 2000)->nullable();
        });
        Schema::create('manufacturing_batch_materials', function (Blueprint $t) {
            $this->line($t, 'manufacturing_batches');
            $this->fk($t, 'bill_of_material_line_id', 'bill_of_material_lines');
            $this->fk($t, 'component_item_id', 'items');
            $t->decimal('planned_net_quantity', 20, 6);
            $t->decimal('planned_gross_quantity', 20, 6);
            $t->unique(['document_id', 'bill_of_material_line_id'], 'uq_p9_batch_material');
        });
        Schema::create('production_reports', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'manufacturing_batch_document_id', 'manufacturing_batches', 'document_id');
            $t->boolean('is_final')->default(false);
            $t->string('report_note', 1000)->nullable();
            $t->index(['manufacturing_batch_document_id', 'is_final'], 'idx_p9_report_final');
        });
        Schema::create('production_material_lines', function (Blueprint $t) {
            $this->line($t, 'production_reports');
            $this->fk($t, 'manufacturing_batch_material_id', 'manufacturing_batch_materials', 'id', true);
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('quantity', 20, 6);
            $t->string('extra_material_reason', 500)->nullable();
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
        });
        Schema::create('production_output_lines', function (Blueprint $t) {
            $this->line($t, 'production_reports');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->string('output_kind', 20)->default('finished');
            $t->decimal('quantity', 20, 6);
            $t->decimal('cost_allocation_fraction', 20, 10);
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
        });
        Schema::create('production_wastage_lines', function (Blueprint $t) {
            $this->line($t, 'production_reports');
            $this->fk($t, 'production_material_line_id', 'production_material_lines');
            $t->string('waste_class', 20);
            $t->decimal('quantity', 20, 6);
            $this->fk($t, 'expense_account_id', 'ledger_accounts', 'id', true);
            $t->decimal('expense_amount_pkr', 20, 4)->default(0);
            $t->string('reason', 500);
        });
        Schema::create('production_cost_allocations', function (Blueprint $t) {
            $this->line($t, 'production_reports');
            $this->fk($t, 'source_journal_line_id', 'journal_lines');
            $this->fk($t, 'production_output_line_id', 'production_output_lines');
            $t->string('cost_category', 30);
            $t->decimal('amount_pkr', 20, 4);
            $this->fk($t, 'absorption_account_id', 'ledger_accounts');
            $t->unique(['document_id', 'source_journal_line_id', 'production_output_line_id', 'cost_category'], 'uq_p9_cost_allocation');
        });
        Schema::table('processing_jobs', function (Blueprint $t) {
            $this->fk($t, 'manufacturing_batch_document_id', 'manufacturing_batches', 'document_id', true);
        });
        $checks = [
            'bills_of_materials' => "version_number>0 AND output_quantity>0 AND state IN ('draft','published','retired') AND is_active IN (0,1)",
            'bill_of_material_lines' => 'line_no>0 AND net_quantity>0 AND planned_wastage_fraction>=0 AND planned_wastage_fraction<1',
            'manufacturing_batches' => 'planned_output_quantity>0 AND (planned_start_on IS NULL OR planned_finish_on IS NULL OR planned_finish_on>=planned_start_on)',
            'manufacturing_batch_materials' => 'line_no>0 AND planned_net_quantity>0 AND planned_gross_quantity>=planned_net_quantity',
            'production_reports' => 'is_final IN (0,1)', 'production_material_lines' => 'line_no>0 AND quantity>0',
            'production_output_lines' => "line_no>0 AND quantity>0 AND cost_allocation_fraction>=0 AND cost_allocation_fraction<=1 AND output_kind IN ('finished','intermediate','byproduct')",
            'production_wastage_lines' => "line_no>0 AND quantity>0 AND expense_amount_pkr>=0 AND ((waste_class='normal' AND expense_account_id IS NULL AND expense_amount_pkr=0) OR (waste_class='abnormal' AND expense_account_id IS NOT NULL))",
            'production_cost_allocations' => "line_no>0 AND amount_pkr>0 AND cost_category IN ('direct_labor','overhead','other_conversion')",
        ];
        foreach ($checks as $table => $expression) {
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT chk_p9_'.substr(sha1($table), 0, 16).' CHECK ('.$expression.')');
        }
    }

    public function down(): void
    {
        Schema::table('processing_jobs', function (Blueprint $t) {
            $t->dropForeign('fk_p9_'.substr(sha1('processing_jobs_manufacturing_batch_document_id'), 0, 16));
            $t->dropColumn('manufacturing_batch_document_id');
        });
        foreach (['production_cost_allocations', 'production_wastage_lines', 'production_output_lines', 'production_material_lines', 'production_reports', 'manufacturing_batch_materials', 'manufacturing_batches', 'bill_of_material_lines', 'bills_of_materials'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
