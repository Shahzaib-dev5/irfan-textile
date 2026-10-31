<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function fk(Blueprint $t, string $c, string $table, string $key = 'id', bool $nullable = false): void
    {
        $t->unsignedBigInteger($c)->nullable($nullable);
        $t->index($c, 'idx_p8_'.substr(sha1($t->getTable().'_'.$c), 0, 16));
        $t->foreign($c, 'fk_p8_'.substr(sha1($t->getTable().'_'.$c), 0, 16))->references($key)->on($table)->restrictOnDelete()->restrictOnUpdate();
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

    private function line(Blueprint $t, string $table): void
    {
        $t->id();
        $this->fk($t, 'document_id', $table, 'document_id');
        $t->unsignedSmallInteger('line_no');
        $t->unique(['document_id', 'line_no']);
        $this->stamp($t);
    }

    private function check(string $table, string $sql): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_p8_".substr(sha1($table), 0, 16)." CHECK ({$sql})");
    }

    public function up(): void
    {
        Schema::create('processing_route_versions', function (Blueprint $t) {
            $t->id();
            $t->string('route_code', 40);
            $t->unsignedInteger('version_number');
            $t->string('name', 150);
            $t->string('state', 20)->default('draft');
            $t->boolean('is_active')->default(true);
            $t->unique(['route_code', 'version_number']);
            $t->index(['state', 'is_active']);
            $this->stamp($t);
        });
        Schema::create('processing_route_steps', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'processing_route_version_id', 'processing_route_versions');
            $t->unsignedSmallInteger('step_number');
            foreach (['processing_service_id' => 'processing_services', 'input_item_id' => 'items', 'output_item_id' => 'items'] as $c => $table) {
                $this->fk($t, $c, $table);
            }
            $t->decimal('planned_yield_ratio', 20, 10)->default(1);
            $t->decimal('permitted_normal_loss_fraction', 20, 10)->default(0);
            $t->unique(['processing_route_version_id', 'step_number'], 'uq_p8_route_step');
            $this->stamp($t);
        });
        Schema::create('processing_jobs', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'processing_route_version_id', 'processing_route_versions');
            $t->decimal('planned_input_quantity', 20, 6);
            $t->date('required_by')->nullable();
            $t->string('instructions', 2000)->nullable();
        });
        Schema::create('processing_job_steps', function (Blueprint $t) {
            $this->line($t, 'processing_jobs');
            foreach (['processing_route_step_id' => 'processing_route_steps', 'processing_service_id' => 'processing_services', 'vendor_service_id' => 'vendor_services', 'vendor_service_rate_id' => 'vendor_service_rates', 'input_item_id' => 'items', 'output_item_id' => 'items', 'billing_unit_id' => 'units'] as $c => $table) {
                $this->fk($t, $c, $table);
            } $t->decimal('planned_input_quantity', 20, 6);
            $t->decimal('planned_output_quantity', 20, 6);
            $t->decimal('agreed_unit_rate_pkr', 20, 8);
            $t->date('rate_effective_date');
            $t->decimal('permitted_normal_loss_fraction', 20, 10)->default(0);
            $t->unique(['document_id', 'processing_route_step_id'], 'uq_p8_job_step');
        });
        foreach (['dispatch', 'receipt'] as $kind) {
            $table = $kind === 'dispatch' ? 'processing_dispatches' : 'processing_receipts';
            Schema::create($table, function (Blueprint $t) use ($kind) {
                $this->header($t);
                $this->fk($t, 'processing_job_document_id', 'processing_jobs', 'document_id');
                $this->fk($t, 'vendor_party_role_id', 'vendor_profiles', 'party_role_id');
                $t->string('contractor_reference', 100)->nullable();
                if ($kind === 'receipt') {
                    $t->string('remarks', 1000)->nullable();
                }
            });
        }
        Schema::create('processing_dispatch_lines', function (Blueprint $t) {
            $this->line($t, 'processing_dispatches');
            $this->fk($t, 'processing_job_step_id', 'processing_job_steps');
            foreach (['source_stock_position_id', 'contractor_stock_position_id'] as $c) {
                $this->fk($t, $c, 'stock_positions');
            }
            $this->fk($t, 'source_stock_cost_layer_id', 'stock_cost_layers');
            $t->decimal('quantity', 20, 6);
            foreach (['source_stock_movement_id', 'contractor_stock_movement_id'] as $c) {
                $this->fk($t, $c, 'stock_movements', 'id', true);
                $t->unique($c);
            }
        });
        Schema::create('processing_receipt_lines', function (Blueprint $t) {
            $this->line($t, 'processing_receipts');
            $this->fk($t, 'processing_job_step_id', 'processing_job_steps');
            $this->fk($t, 'processing_dispatch_line_id', 'processing_dispatch_lines');
            $t->string('disposition', 20)->default('accepted');
            foreach (['source_stock_position_id', 'output_stock_position_id'] as $c) {
                $this->fk($t, $c, 'stock_positions');
            }
            foreach (['source_quantity', 'output_quantity', 'output_source_equivalent_quantity', 'normal_loss_quantity', 'abnormal_loss_quantity', 'accepted_service_quantity'] as $c) {
                $t->decimal($c, 20, 6)->default(0);
            }
            foreach (['service_accrual_pkr', 'abnormal_loss_cost_pkr'] as $c) {
                $t->decimal($c, 20, 4)->default(0);
            }
            $this->fk($t, 'abnormal_loss_account_id', 'ledger_accounts', 'id', true);
            foreach (['source_stock_movement_id', 'output_stock_movement_id'] as $c) {
                $this->fk($t, $c, 'stock_movements', 'id', true);
                $t->unique($c);
            }
        });
        Schema::create('fabric_roll_lineage', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'document_id', 'documents');
            foreach (['parent_roll_id', 'child_roll_id'] as $c) {
                $this->fk($t, $c, 'fabric_rolls');
            }
            $t->decimal('parent_quantity_used', 20, 6);
            $t->unique(['document_id', 'parent_roll_id', 'child_roll_id'], 'uq_p8_roll_lineage');
            $t->engine = 'InnoDB';
            $t->dateTime('created_at', 6)->useCurrent();
        });
        Schema::create('purchase_invoice_processing_matches', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'purchase_invoice_line_id', 'purchase_invoice_lines');
            $this->fk($t, 'processing_receipt_line_id', 'processing_receipt_lines');
            $t->decimal('billed_service_quantity', 20, 6);
            $t->decimal('net_amount', 20, 4);
            $t->decimal('accrued_value_relieved', 20, 4);
            $t->unique(['purchase_invoice_line_id', 'processing_receipt_line_id'], 'uq_p8_bill_receipt');
            $this->stamp($t);
        });
        $this->check('processing_route_versions', "version_number>0 AND state IN ('draft','published','retired')");
        $this->check('processing_route_steps', 'step_number>0 AND planned_yield_ratio>0 AND permitted_normal_loss_fraction>=0 AND permitted_normal_loss_fraction<1');
        $this->check('processing_jobs', 'planned_input_quantity>0');
        $this->check('processing_job_steps', 'line_no>0 AND planned_input_quantity>0 AND planned_output_quantity>0 AND agreed_unit_rate_pkr>=0 AND permitted_normal_loss_fraction>=0 AND permitted_normal_loss_fraction<1');
        $this->check('processing_dispatch_lines', 'line_no>0 AND quantity>0 AND source_stock_position_id<>contractor_stock_position_id');
        $this->check('processing_receipt_lines', "line_no>0 AND disposition IN ('accepted','rejected') AND source_stock_position_id<>output_stock_position_id AND source_quantity>0 AND output_quantity>0 AND output_source_equivalent_quantity>0 AND normal_loss_quantity>=0 AND abnormal_loss_quantity>=0 AND accepted_service_quantity>=0 AND service_accrual_pkr>=0 AND abnormal_loss_cost_pkr>=0 AND source_quantity=output_source_equivalent_quantity+normal_loss_quantity+abnormal_loss_quantity AND ((abnormal_loss_quantity=0 AND abnormal_loss_account_id IS NULL) OR (abnormal_loss_quantity>0 AND abnormal_loss_account_id IS NOT NULL)) AND (disposition='accepted' OR (normal_loss_quantity=0 AND abnormal_loss_quantity=0 AND accepted_service_quantity=0 AND service_accrual_pkr=0 AND source_quantity=output_quantity))");
        $this->check('fabric_roll_lineage', 'parent_roll_id<>child_roll_id AND parent_quantity_used>0');
        $this->check('purchase_invoice_processing_matches', 'billed_service_quantity>0 AND net_amount>0 AND accrued_value_relieved=net_amount');
        Schema::table('purchase_invoice_lines', function (Blueprint $t) {
            $t->foreign('processing_job_step_id', 'fk_p8_invoice_step')->references('id')->on('processing_job_steps')->restrictOnDelete()->restrictOnUpdate();
        });
        // MySQL and MariaDB use different CHECK removal syntax. No existing data is rewritten.
        $maria = str_contains(strtolower(DB::selectOne('SELECT VERSION() AS v')->v), 'mariadb');
        DB::statement('ALTER TABLE purchase_invoice_lines DROP '.($maria ? 'CONSTRAINT' : 'CHECK').' chk_purchase_invoice_lines_values');
        $this->check('purchase_invoice_lines', "line_no>0 AND ((line_kind='stock' AND item_id IS NOT NULL AND processing_job_step_id IS NULL) OR (line_kind='processing' AND item_id IS NULL AND processing_job_step_id IS NOT NULL)) AND expense_account_id IS NULL AND cost_centre_id IS NULL AND quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0 AND net_amount>=0 AND tax_amount=0 AND gross_amount=net_amount");
    }

    public function down(): void
    {
        throw new LogicException('Forward-only operational migration; restore an approved backup instead.');
    }
};
