<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function timestamps(Blueprint $t): void
    {
        $t->dateTime('created_at', 6)->useCurrent();
        $t->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
    }

    private function fk(Blueprint $t, string $column, string $table, string $key = 'id', bool $nullable = false): void
    {
        $t->unsignedBigInteger($column)->nullable($nullable)->index();
        $name = 'fk_p7_'.substr(sha1($t->getTable().'_'.$column), 0, 16);
        $t->foreign($column, $name)->references($key)->on($table)->restrictOnUpdate()->restrictOnDelete();
    }

    private function header(Blueprint $t): void
    {
        $t->engine = 'InnoDB';
        $t->unsignedBigInteger('document_id')->primary();
        $t->foreign('document_id')->references('id')->on('documents')->restrictOnUpdate()->restrictOnDelete();
        $this->timestamps($t);
    }

    private function line(Blueprint $t, string $table): void
    {
        $t->engine = 'InnoDB';
        $t->id();
        $this->fk($t, 'document_id', $table, 'document_id');
        $t->unsignedSmallInteger('line_no');
        $t->unique(['document_id', 'line_no']);
        $this->timestamps($t);
    }

    private function check(string $table, string $suffix, string $sql): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$table}_{$suffix} CHECK ({$sql})");
    }

    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'supplier_party_role_id', 'supplier_profiles', 'party_role_id');
            $this->fk($t, 'ship_to_warehouse_id', 'warehouses');
            $this->fk($t, 'payment_term_id', 'payment_terms', 'id', true);
            $t->date('expected_on')->nullable();
            $t->string('supplier_reference', 100)->nullable();
            $t->text('notes')->nullable();
            $t->index(['supplier_party_role_id', 'expected_on']);
        });
        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $this->line($t, 'purchase_orders');
            $this->fk($t, 'item_id', 'items');
            $t->string('description', 255);
            $this->fk($t, 'unit_id', 'units');
            $t->decimal('quantity', 20, 6);
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('conversion_factor', 20, 8);
            $t->decimal('unit_price', 20, 8);
            $t->decimal('discount_amount', 20, 4)->default(0);
            $t->unsignedBigInteger('tax_rate_id')->nullable()->index(); // NULL-only until tax configuration is authorized.
            $t->date('expected_on')->nullable();
            $t->index(['item_id', 'document_id']);
        });
        Schema::create('goods_receipts', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'supplier_party_role_id', 'supplier_profiles', 'party_role_id');
            $this->fk($t, 'warehouse_id', 'warehouses');
            $t->string('supplier_challan_number', 100)->nullable();
            $t->date('supplier_challan_date')->nullable();
            $t->dateTime('received_at', 6);
            $t->text('notes')->nullable();
            $t->index(['supplier_party_role_id', 'supplier_challan_number'], 'idx_gr_supplier_challan');
            $t->index(['warehouse_id', 'received_at']);
        });
        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $this->line($t, 'goods_receipts');
            $this->fk($t, 'purchase_order_line_id', 'purchase_order_lines', 'id', true);
            $this->fk($t, 'item_id', 'items');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $this->fk($t, 'unit_id', 'units');
            foreach (['received_quantity', 'accepted_quantity', 'rejected_quantity', 'accepted_base_quantity'] as $column) {
                $definition = $t->decimal($column, 20, 6);
                if ($column === 'rejected_quantity') {
                    $definition->default(0);
                }
            }
            $t->decimal('conversion_factor', 20, 8);
            $t->decimal('provisional_unit_cost', 20, 8);
            $t->decimal('provisional_value', 20, 4);
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
            $t->string('inspection_notes', 500)->nullable();
            $t->index(['purchase_order_line_id', 'document_id']);
        });
        Schema::create('purchase_invoices', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'payee_party_role_id', 'party_roles');
            $t->string('supplier_invoice_number', 100);
            $t->date('supplier_invoice_date');
            $t->date('due_on');
            $this->fk($t, 'payment_term_id', 'payment_terms', 'id', true);
            $t->decimal('net_amount', 20, 4);
            $t->decimal('tax_amount', 20, 4)->default(0);
            $t->decimal('rounding_amount', 20, 4)->default(0);
            $t->decimal('gross_amount', 20, 4);
            $t->text('notes')->nullable();
            $t->unique(['payee_party_role_id', 'supplier_invoice_number'], 'uq_pi_role_reference');
            $t->index(['payee_party_role_id', 'due_on']);
        });
        Schema::create('purchase_invoice_lines', function (Blueprint $t) {
            $this->line($t, 'purchase_invoices');
            $t->enum('line_kind', ['stock', 'processing', 'expense']);
            $this->fk($t, 'item_id', 'items', 'id', true);
            $t->unsignedBigInteger('processing_job_step_id')->nullable()->index(); // Deferred FK, blocked until Processing.
            $this->fk($t, 'expense_account_id', 'ledger_accounts', 'id', true);
            $this->fk($t, 'cost_centre_id', 'cost_centres', 'id', true);
            $t->string('description', 255);
            $this->fk($t, 'unit_id', 'units');
            $t->decimal('quantity', 20, 6);
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('conversion_factor', 20, 8);
            $t->decimal('unit_price', 20, 8);
            foreach (['discount_amount', 'net_amount', 'tax_amount', 'gross_amount'] as $column) {
                $definition = $t->decimal($column, 20, 4);
                if (in_array($column, ['discount_amount', 'tax_amount'], true)) {
                    $definition->default(0);
                }
            }
        });
        Schema::create('purchase_invoice_receipt_matches', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'purchase_invoice_line_id', 'purchase_invoice_lines');
            $this->fk($t, 'goods_receipt_line_id', 'goods_receipt_lines');
            $t->decimal('matched_base_quantity', 20, 6);
            $t->decimal('net_amount', 20, 4);
            $t->decimal('provisional_value_relieved', 20, 4);
            $this->timestamps($t);
            $t->unique(['purchase_invoice_line_id', 'goods_receipt_line_id'], 'uq_pi_receipt_match');
        });
        Schema::create('purchase_returns', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'supplier_party_role_id', 'supplier_profiles', 'party_role_id');
            $this->fk($t, 'warehouse_id', 'warehouses');
            $t->string('return_reference', 100)->nullable();
            $t->string('reason', 500);
            $t->index(['supplier_party_role_id', 'document_id']);
        });
        Schema::create('purchase_return_lines', function (Blueprint $t) {
            $this->line($t, 'purchase_returns');
            $this->fk($t, 'goods_receipt_line_id', 'goods_receipt_lines');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('base_quantity', 20, 6);
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
            $t->string('reason', 500);
            $t->index(['goods_receipt_line_id', 'document_id']);
        });
        Schema::create('party_open_items', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'journal_line_id', 'journal_lines');
            $t->unique('journal_line_id');
            $t->decimal('original_amount', 20, 4);
            $t->date('due_on');
            $t->dateTime('created_at', 6)->useCurrent();
            $t->index(['due_on', 'id']);
        });
        $this->check('purchase_order_lines', 'values', 'line_no>0 AND quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0 AND tax_rate_id IS NULL');
        $this->check('goods_receipt_lines', 'values', 'line_no>0 AND received_quantity>0 AND accepted_quantity>0 AND rejected_quantity>=0 AND received_quantity=accepted_quantity+rejected_quantity AND conversion_factor>0 AND accepted_base_quantity>0 AND provisional_unit_cost>=0 AND provisional_value>=0');
        $this->check('purchase_invoices', 'totals', 'net_amount>=0 AND tax_amount=0 AND rounding_amount=0 AND gross_amount>0 AND gross_amount=net_amount');
        $this->check('purchase_invoices', 'dates', 'due_on>=supplier_invoice_date');
        $this->check('purchase_invoice_lines', 'values', "line_no>0 AND line_kind='stock' AND item_id IS NOT NULL AND processing_job_step_id IS NULL AND expense_account_id IS NULL AND cost_centre_id IS NULL AND quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0 AND net_amount>=0 AND tax_amount=0 AND gross_amount=net_amount");
        $this->check('purchase_invoice_receipt_matches', 'values', 'matched_base_quantity>0 AND net_amount>=0 AND provisional_value_relieved>=0');
        $this->check('purchase_return_lines', 'quantity', 'line_no>0 AND base_quantity>0');
        $this->check('party_open_items', 'amount', 'original_amount>0');
    }

    public function down(): void
    {
        throw new LogicException('Purchasing history requires a reviewed forward migration.');
    }
};
