<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function timestamps(Blueprint $t, bool $immutable = false): void
    {
        $t->dateTime('created_at', 6)->useCurrent();
        if (! $immutable) {
            $t->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
        }
    }

    private function fk(Blueprint $t, string $column, string $target, string $key = 'id', bool $nullable = false): void
    {
        $t->unsignedBigInteger($column)->nullable($nullable)->index();
        $t->foreign($column)->references($key)->on($target)->restrictOnDelete()->restrictOnUpdate();
    }

    private function check(string $table, string $suffix, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$table}_{$suffix} CHECK ({$expression})");
    }

    private function header(Blueprint $t): void
    {
        $t->unsignedBigInteger('document_id')->primary();
        $t->foreign('document_id')->references('id')->on('documents')->restrictOnDelete()->restrictOnUpdate();
        $this->timestamps($t);
    }

    private function line(Blueprint $t, string $header): void
    {
        $t->id();
        $this->fk($t, 'document_id', $header, 'document_id');
        $t->unsignedSmallInteger('line_no');
        $t->unique(['document_id', 'line_no']);
        $this->timestamps($t);
    }

    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 100);
            $t->string('module_code', 40);
            $t->string('prefix', 12)->unique();
            $t->unsignedTinyInteger('numbering_width')->default(6);
            $t->boolean('requires_open_period')->default(true);
            $t->boolean('is_active')->default(true);
            $this->timestamps($t);
        });
        Schema::create('document_sequences', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'document_type_id', 'document_types');
            $this->fk($t, 'financial_year_id', 'financial_years');
            $t->unsignedBigInteger('next_value')->default(1);
            $t->unique(['document_type_id', 'financial_year_id']);
            $this->timestamps($t);
        });
        Schema::create('documents', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'document_type_id', 'document_types');
            $t->string('number', 64)->nullable()->unique();
            $t->date('document_date');
            $this->fk($t, 'financial_year_id', 'financial_years');
            $t->unsignedBigInteger('accounting_period_id');
            $t->index(['accounting_period_id', 'financial_year_id']);
            $t->foreign(['accounting_period_id', 'financial_year_id'], 'fk_documents_period_year')->references(['id', 'financial_year_id'])->on('accounting_periods')->restrictOnDelete()->restrictOnUpdate();
            $t->string('status', 12)->default('draft');
            $t->char('idempotency_key', 36)->unique();
            $t->char('posting_key', 36)->nullable()->unique();
            $t->binary('payload_hash', 32, true)->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $this->fk($t, 'created_by', 'users');
            $this->fk($t, 'posted_by', 'users', 'id', true);
            $t->dateTime('posted_at', 6)->nullable();
            $this->fk($t, 'reversal_of_document_id', 'documents', 'id', true);
            $t->unique('reversal_of_document_id');
            $this->fk($t, 'reversed_by', 'users', 'id', true);
            $t->dateTime('reversed_at', 6)->nullable();
            $t->string('reason', 1000)->nullable();
            $t->string('external_reference', 100)->nullable();
            $t->index(['document_type_id', 'document_date', 'id']);
            $t->index(['status', 'document_date', 'id']);
            $this->timestamps($t);
        });
        Schema::create('document_status_events', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'document_id', 'documents');
            $t->unsignedInteger('event_no');
            $t->string('from_status', 12)->nullable();
            $t->string('to_status', 12);
            $this->fk($t, 'actor_id', 'users');
            $t->string('reason', 1000)->nullable();
            $t->char('request_key', 36);
            $t->unique(['document_id', 'event_no']);
            $t->index(['actor_id', 'created_at']);
            $this->timestamps($t, true);
        });
        Schema::table('audit_events', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->foreign('document_id', 'fk_audit_events_document')->references('id')->on('documents')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('ledger_accounts', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 150);
            $this->fk($t, 'parent_id', 'ledger_accounts', 'id', true);
            $t->enum('account_type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $t->enum('normal_balance', ['debit', 'credit']);
            $t->enum('control_type', ['none', 'party_receivable', 'party_payable', 'inventory', 'bank_cash'])->default('none');
            $t->boolean('is_postable')->default(true);
            $t->boolean('is_active')->default(true);
            $t->index(['account_type', 'is_active']);
            $this->timestamps($t);
        });
        Schema::create('cost_centres', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 150);
            $this->fk($t, 'parent_id', 'cost_centres', 'id', true);
            $t->boolean('is_active')->default(true);
            $this->timestamps($t);
        });
        Schema::create('posting_accounts', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('purpose_code', 60)->unique();
            $this->fk($t, 'ledger_account_id', 'ledger_accounts');
            $t->string('description', 255);
            $this->timestamps($t);
        });
        Schema::create('journal_entries', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $this->header($t);
            $t->enum('entry_kind', ['operational', 'manual', 'opening', 'reversal', 'year_close']);
            $t->string('description', 500);
            $t->decimal('total_debit', 20, 4);
            $t->decimal('total_credit', 20, 4);
        });
        Schema::create('journal_lines', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'journal_entry_id', 'journal_entries', 'document_id');
            $t->unsignedSmallInteger('line_no');
            $this->fk($t, 'ledger_account_id', 'ledger_accounts');
            $this->fk($t, 'party_id', 'parties', 'id', true);
            $this->fk($t, 'party_role_id', 'party_roles', 'id', true);
            $t->unsignedBigInteger('employee_id')->nullable()->index(); // FK deferred until the approved employee master exists.
            $this->fk($t, 'cost_centre_id', 'cost_centres', 'id', true);
            $t->string('description', 500);
            $t->decimal('debit', 20, 4)->default(0);
            $t->decimal('credit', 20, 4)->default(0);
            $t->date('due_on')->nullable();
            $t->unique(['journal_entry_id', 'line_no']);
            $t->index(['ledger_account_id', 'journal_entry_id']);
            $t->index(['party_id', 'ledger_account_id', 'journal_entry_id']);
            $t->index(['employee_id', 'journal_entry_id']);
            $this->timestamps($t, true);
            $t->foreign(['party_role_id', 'party_id'], 'fk_journal_lines_party_role')->references(['id', 'party_id'])->on('party_roles')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('warehouses', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 120);
            $t->string('address', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->index(['is_active', 'name']);
            $this->timestamps($t);
        });
        Schema::create('warehouse_locations', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'warehouse_id', 'warehouses');
            $t->string('code', 30);
            $t->string('name', 120);
            $t->string('location_type', 20);
            $this->fk($t, 'contractor_vendor_party_role_id', 'vendor_profiles', 'party_role_id', true);
            $t->boolean('is_active')->default(true);
            $t->unique(['warehouse_id', 'code']);
            $t->index(['contractor_vendor_party_role_id', 'is_active'], 'idx_location_contractor_active');
            $this->timestamps($t);
        });
        Schema::create('stock_lots', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'item_id', 'items');
            $t->string('lot_number', 50)->unique();
            $this->fk($t, 'origin_document_id', 'documents');
            $t->string('external_lot_reference', 100)->nullable();
            $t->date('manufactured_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('quality_status', 20)->default('pending');
            $t->index(['item_id', 'quality_status']);
            $this->timestamps($t);
        });
        Schema::create('fabric_rolls', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'stock_lot_id', 'stock_lots');
            $t->string('roll_number', 60)->unique();
            $t->string('external_roll_reference', 100)->nullable();
            $t->decimal('width_mm', 12, 3)->nullable();
            $t->boolean('is_active')->default(true);
            $t->index(['stock_lot_id', 'is_active']);
            $this->timestamps($t);
        });
        Schema::create('stock_positions', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'stock_lot_id', 'stock_lots');
            $this->fk($t, 'warehouse_location_id', 'warehouse_locations');
            $this->fk($t, 'fabric_roll_id', 'fabric_rolls', 'id', true);
            $t->unsignedBigInteger('roll_key')->storedAs('IFNULL(fabric_roll_id,0)');
            $t->unique(['stock_lot_id', 'warehouse_location_id', 'roll_key'], 'uq_stock_position_dimension');
            $t->index(['warehouse_location_id', 'stock_lot_id']);
            $this->timestamps($t);
        });
        Schema::create('stock_balances', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->unsignedBigInteger('stock_position_id')->primary();
            $t->foreign('stock_position_id')->references('id')->on('stock_positions')->restrictOnDelete()->restrictOnUpdate();
            $t->decimal('on_hand_quantity', 20, 6)->default(0);
            $t->decimal('reserved_quantity', 20, 6)->default(0);
            $t->unsignedBigInteger('lock_version')->default(0);
            $this->timestamps($t);
        });
        Schema::create('stock_movements', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'document_id', 'documents');
            $t->unsignedInteger('sequence');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('quantity_delta', 20, 6);
            $t->string('movement_reason', 30);
            $this->fk($t, 'reversal_of_movement_id', 'stock_movements', 'id', true);
            $t->unique('reversal_of_movement_id');
            $t->unique(['document_id', 'sequence']);
            $t->index(['stock_position_id', 'id']);
            $this->timestamps($t, true);
        });
        Schema::create('stock_cost_layers', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $this->fk($t, 'receipt_stock_movement_id', 'stock_movements');
            $t->unique('receipt_stock_movement_id');
            $t->date('fifo_date');
            $t->decimal('remaining_quantity', 20, 6);
            $t->index(['stock_position_id', 'fifo_date', 'receipt_stock_movement_id', 'id'], 'idx_stock_layers_fifo');
            $this->timestamps($t);
        });
        Schema::create('inventory_layer_allocations', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'stock_movement_id', 'stock_movements');
            $this->fk($t, 'stock_cost_layer_id', 'stock_cost_layers');
            $t->decimal('quantity_delta', 20, 6);
            $t->unique(['stock_movement_id', 'stock_cost_layer_id'], 'uq_layer_allocation_movement_layer');
            $this->timestamps($t, true);
        });
        Schema::create('inventory_value_entries', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'document_id', 'documents');
            $t->unsignedInteger('sequence');
            $this->fk($t, 'stock_cost_layer_id', 'stock_cost_layers');
            $this->fk($t, 'inventory_layer_allocation_id', 'inventory_layer_allocations', 'id', true);
            $t->decimal('amount_pkr', 20, 4);
            $t->string('value_reason', 30);
            $this->fk($t, 'reversal_of_value_entry_id', 'inventory_value_entries', 'id', true);
            $t->unique('reversal_of_value_entry_id');
            $t->unique(['document_id', 'sequence']);
            $t->index(['stock_cost_layer_id', 'id']);
            $this->timestamps($t, true);
        });
        Schema::create('inventory_cost_links', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'document_id', 'documents');
            $t->unsignedInteger('sequence');
            $this->fk($t, 'source_layer_allocation_id', 'inventory_layer_allocations');
            $this->fk($t, 'destination_stock_cost_layer_id', 'stock_cost_layers', 'id', true);
            $this->fk($t, 'expense_account_id', 'ledger_accounts', 'id', true);
            $t->decimal('allocation_fraction', 20, 10);
            $t->decimal('allocated_cost_pkr', 20, 4);
            $t->unique(['document_id', 'sequence']);
            $this->timestamps($t, true);
        });
        Schema::create('stock_reservations', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'document_id', 'documents');
            $t->unsignedInteger('sequence');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('quantity', 20, 6);
            $t->decimal('fulfilled_quantity', 20, 6)->default(0);
            $t->decimal('released_quantity', 20, 6)->default(0);
            $t->dateTime('expires_at', 6)->nullable();
            $t->unique(['document_id', 'sequence']);
            $t->index(['stock_position_id', 'expires_at']);
            $this->timestamps($t);
        });
        Schema::create('inventory_transfers', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $this->header($t);
            $this->fk($t, 'related_transfer_document_id', 'inventory_transfers', 'document_id', true);
            $t->string('remarks', 500)->nullable();
        });
        Schema::create('inventory_transfer_lines', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $this->line($t, 'inventory_transfers');
            $this->fk($t, 'source_stock_position_id', 'stock_positions');
            $this->fk($t, 'destination_stock_position_id', 'stock_positions');
            $t->decimal('quantity', 20, 6);
            $this->fk($t, 'source_stock_cost_layer_id', 'stock_cost_layers');
            $this->fk($t, 'source_stock_movement_id', 'stock_movements', 'id', true);
            $this->fk($t, 'destination_stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('source_stock_movement_id');
            $t->unique('destination_stock_movement_id');
        });
        Schema::create('inventory_adjustments', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $this->header($t);
            $t->string('adjustment_reason', 20);
            $t->string('reference', 120)->nullable();
            $t->string('explanation', 1000);
        });
        Schema::create('inventory_adjustment_lines', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $this->line($t, 'inventory_adjustments');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('quantity_delta', 20, 6);
            $t->decimal('counted_quantity', 20, 6)->nullable();
            $t->decimal('expected_quantity', 20, 6)->nullable();
            $this->fk($t, 'count_cutoff_movement_id', 'stock_movements', 'id', true);
            $t->decimal('positive_unit_cost_pkr', 20, 8)->nullable();
            $this->fk($t, 'offset_account_id', 'ledger_accounts');
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
        });
        foreach (['document_types', 'ledger_accounts', 'cost_centres', 'warehouses', 'warehouse_locations', 'fabric_rolls'] as $table) {
            $this->check($table, 'active', 'is_active IN (0,1)');
        }
        $this->check('document_types', 'config', 'numbering_width BETWEEN 4 AND 12 AND requires_open_period IN (0,1)');
        $this->check('document_sequences', 'next', 'next_value > 0');
        $states = "'draft','posted','reversed','cancelled'";
        $this->check('documents', 'state', "status IN ({$states})");
        $this->check('documents', 'posting', "(status IN ('draft','cancelled') AND number IS NULL AND posting_key IS NULL AND payload_hash IS NULL AND posted_by IS NULL AND posted_at IS NULL) OR (status IN ('posted','reversed') AND number IS NOT NULL AND posting_key IS NOT NULL AND payload_hash IS NOT NULL AND posted_by IS NOT NULL AND posted_at IS NOT NULL)");
        $this->check('documents', 'reversed', "(status = 'reversed' AND reversed_by IS NOT NULL AND reversed_at IS NOT NULL) OR (status <> 'reversed' AND reversed_by IS NULL AND reversed_at IS NULL)");
        $this->check('documents', 'reason', "(status NOT IN ('cancelled','reversed') AND reversal_of_document_id IS NULL) OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason))>0)");
        $this->check('document_status_events', 'states', "event_no>0 AND (from_status IS NULL OR from_status IN ({$states})) AND to_status IN ({$states})");
        $this->check('ledger_accounts', 'flags', "is_postable IN (0,1) AND (control_type = 'none' OR is_postable=1)");
        $this->check('journal_entries', 'balanced', 'total_debit>0 AND total_debit=total_credit');
        $this->check('journal_lines', 'sides', '(debit>0 AND credit=0) OR (credit>0 AND debit=0)');
        $this->check('journal_lines', 'dimensions', '(party_role_id IS NULL OR party_id IS NOT NULL) AND employee_id IS NULL');
        $this->check('warehouse_locations', 'type', "location_type IN ('internal','contractor','transit','quarantine')");
        $this->check('warehouse_locations', 'custody', "(location_type='contractor' AND contractor_vendor_party_role_id IS NOT NULL) OR (location_type<>'contractor' AND contractor_vendor_party_role_id IS NULL)");
        $this->check('stock_lots', 'quality', "quality_status IN ('pending','released','held','rejected')");
        $this->check('stock_lots', 'dates', 'expires_on IS NULL OR manufactured_on IS NULL OR expires_on>=manufactured_on');
        $this->check('fabric_rolls', 'width', 'width_mm IS NULL OR width_mm>0');
        $this->check('stock_balances', 'nonnegative', 'on_hand_quantity>=0 AND reserved_quantity>=0 AND reserved_quantity<=on_hand_quantity');
        $this->check('stock_movements', 'quantity', 'sequence>0 AND quantity_delta<>0');
        $this->check('stock_movements', 'reason', "movement_reason IN ('purchase_receipt','purchase_return','transfer','processing','production','packing','sales_dispatch','sales_return','opening','count','damage','reversal')");
        $this->check('stock_cost_layers', 'remaining', 'remaining_quantity>=0');
        $this->check('inventory_layer_allocations', 'quantity', 'quantity_delta<>0');
        $this->check('inventory_value_entries', 'amount', 'sequence>0 AND amount_pkr<>0');
        $this->check('inventory_value_entries', 'reason', "value_reason IN ('receipt','issue','conversion','service_accrual','direct_cost','late_cost','write_down','rounding','reversal')");
        $this->check('inventory_cost_links', 'target', '(destination_stock_cost_layer_id IS NULL) <> (expense_account_id IS NULL)');
        $this->check('inventory_cost_links', 'allocation', 'sequence>0 AND allocation_fraction>0 AND allocation_fraction<=1 AND allocated_cost_pkr>=0');
        $this->check('stock_reservations', 'quantity', 'sequence>0 AND quantity>0 AND fulfilled_quantity>=0 AND released_quantity>=0 AND fulfilled_quantity+released_quantity<=quantity');
        $this->check('inventory_transfer_lines', 'quantity', 'quantity>0 AND source_stock_position_id<>destination_stock_position_id');
        $this->check('inventory_adjustments', 'reason', "adjustment_reason IN ('opening','count','damage','write_off')");
        $this->check('inventory_adjustment_lines', 'quantity', 'quantity_delta<>0 AND (counted_quantity IS NULL OR counted_quantity>=0) AND (expected_quantity IS NULL OR expected_quantity>=0)');
        $this->check('inventory_adjustment_lines', 'cost', '(quantity_delta>0 AND positive_unit_cost_pkr IS NOT NULL AND positive_unit_cost_pkr>=0) OR (quantity_delta<0 AND positive_unit_cost_pkr IS NULL)');
    }

    public function down(): void
    {
        throw new LogicException('Posted stock and financial history requires reviewed forward migrations.');
    }
};
