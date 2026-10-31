<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function fk(Blueprint $t, string $column, string $table, string $key = 'id', bool $nullable = false): void
    {
        $t->unsignedBigInteger($column)->nullable($nullable)->index();
        $t->foreign($column, 'fk_s11_'.substr(sha1($t->getTable().$column), 0, 18))->references($key)->on($table)->restrictOnUpdate()->restrictOnDelete();
    }

    private function header(Blueprint $t): void
    {
        $t->engine = 'InnoDB';
        $t->unsignedBigInteger('document_id')->primary();
        $t->foreign('document_id')->references('id')->on('documents')->restrictOnUpdate()->restrictOnDelete();
        $t->timestamps(6);
    }

    private function line(Blueprint $t, string $parent): void
    {
        $t->engine = 'InnoDB';
        $t->id();
        $this->fk($t, 'document_id', $parent, 'document_id');
        $t->unsignedSmallInteger('line_no');
        $t->unique(['document_id', 'line_no']);
        $t->timestamps(6);
    }

    private function check(string $table, string $rule): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_s11_{$table} CHECK ({$rule})");
    }

    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'customer_party_role_id', 'customer_profiles', 'party_role_id');
            $this->fk($t, 'payment_term_id', 'payment_terms', 'id', true);
            $t->string('customer_reference', 100)->nullable();
            $t->date('required_on')->nullable();
            $this->fk($t, 'ship_to_address_id', 'party_addresses', 'id', true);
            $t->text('ship_to_snapshot');
            $t->text('notes')->nullable();
            $t->index(['customer_party_role_id', 'required_on']);
        });
        Schema::create('sales_order_lines', function (Blueprint $t) {
            $this->line($t, 'sales_orders');
            $t->unique(['id', 'document_id'], 'uq_s11_order_line_parent');
            $this->fk($t, 'item_id', 'items');
            $t->string('description', 255);
            $this->fk($t, 'unit_id', 'units');
            foreach (['quantity', 'base_quantity'] as $c) {
                $t->decimal($c, 20, 6);
            }
            foreach (['conversion_factor', 'unit_price'] as $c) {
                $t->decimal($c, 20, 8);
            }
            $t->decimal('discount_amount', 20, 4)->default(0);
            $t->unsignedBigInteger('tax_rate_id')->nullable()->index(); // NULL-only until tax is authorized.
            $t->date('required_on')->nullable();
            $t->index(['item_id', 'document_id']);
        });
        Schema::create('sales_dispatches', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'customer_party_role_id', 'customer_profiles', 'party_role_id');
            $this->fk($t, 'warehouse_id', 'warehouses');
            $t->dateTime('dispatched_at', 6);
            $t->string('carrier', 150)->nullable();
            $t->string('tracking_reference', 100)->nullable();
            $t->text('ship_to_snapshot');
            $t->text('notes')->nullable();
            $t->index(['customer_party_role_id', 'dispatched_at']);
        });
        Schema::create('sales_dispatch_lines', function (Blueprint $t) {
            $this->line($t, 'sales_dispatches');
            $this->fk($t, 'sales_order_line_id', 'sales_order_lines');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('net_revenue_amount', 20, 4);
            $this->fk($t, 'revenue_account_id', 'ledger_accounts');
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
            $this->fk($t, 'packing_package_id', 'packages', 'id', true);
            $t->string('description', 255);
            $t->index(['sales_order_line_id', 'document_id']);
        });
        Schema::create('sales_invoices', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'customer_party_role_id', 'customer_profiles', 'party_role_id');
            $t->date('due_on');
            $this->fk($t, 'payment_term_id', 'payment_terms', 'id', true);
            $t->text('billing_address_snapshot');
            $t->string('customer_reference', 100)->nullable();
            foreach (['net_amount', 'tax_amount', 'rounding_amount', 'gross_amount'] as $c) {
                $t->decimal($c, 20, 4)->default(0);
            }
            $t->text('notes')->nullable();
            $t->index(['customer_party_role_id', 'due_on']);
        });
        Schema::create('sales_invoice_lines', function (Blueprint $t) {
            $this->line($t, 'sales_invoices');
            $this->fk($t, 'sales_order_line_id', 'sales_order_lines');
            $this->fk($t, 'item_id', 'items');
            $t->string('description', 255);
            $this->fk($t, 'unit_id', 'units');
            foreach (['quantity', 'base_quantity'] as $c) {
                $t->decimal($c, 20, 6);
            }
            foreach (['conversion_factor', 'unit_price'] as $c) {
                $t->decimal($c, 20, 8);
            }
            foreach (['discount_amount', 'net_amount', 'tax_amount', 'gross_amount'] as $c) {
                $t->decimal($c, 20, 4)->default(0);
            }
            $this->fk($t, 'cost_centre_id', 'cost_centres', 'id', true);
        });
        Schema::create('sales_invoice_dispatch_matches', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $this->fk($t, 'sales_invoice_line_id', 'sales_invoice_lines');
            $this->fk($t, 'sales_dispatch_line_id', 'sales_dispatch_lines');
            $t->decimal('matched_base_quantity', 20, 6);
            $t->decimal('net_amount', 20, 4);
            $t->unique(['sales_invoice_line_id', 'sales_dispatch_line_id'], 'uq_sales_invoice_dispatch');
            $t->timestamps(6);
        });
        Schema::create('sales_returns', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'customer_party_role_id', 'customer_profiles', 'party_role_id');
            $this->fk($t, 'warehouse_id', 'warehouses');
            $t->dateTime('received_at', 6);
            $t->string('reason', 500);
            $t->index(['customer_party_role_id', 'received_at']);
        });
        Schema::create('sales_return_lines', function (Blueprint $t) {
            $this->line($t, 'sales_returns');
            $this->fk($t, 'sales_dispatch_line_id', 'sales_dispatch_lines');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('base_quantity', 20, 6);
            $t->enum('condition_code', ['saleable', 'quarantine', 'damaged']);
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
            $t->string('reason', 500);
            $t->index(['sales_dispatch_line_id', 'document_id']);
        });
        Schema::create('sales_credit_notes', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'sales_invoice_id', 'sales_invoices', 'document_id');
            $t->enum('credit_kind', ['physical_return', 'price_adjustment']);
            $t->string('reason', 500);
            foreach (['net_amount', 'tax_amount', 'rounding_amount', 'gross_amount'] as $c) {
                $t->decimal($c, 20, 4)->default(0);
            }
        });
        Schema::create('sales_credit_note_lines', function (Blueprint $t) {
            $this->line($t, 'sales_credit_notes');
            $this->fk($t, 'sales_invoice_line_id', 'sales_invoice_lines');
            $this->fk($t, 'sales_invoice_dispatch_match_id', 'sales_invoice_dispatch_matches');
            $this->fk($t, 'sales_return_line_id', 'sales_return_lines', 'id', true);
            $t->decimal('credited_base_quantity', 20, 6)->default(0);
            foreach (['net_amount', 'tax_amount', 'gross_amount'] as $c) {
                $t->decimal($c, 20, 4)->default(0);
            }
            $t->string('description', 255);
        });
        Schema::table('stock_reservations', function (Blueprint $t) {
            $this->fk($t, 'sales_order_line_id', 'sales_order_lines', 'id', true);
            $t->foreign(['sales_order_line_id', 'document_id'], 'fk_s11_reservation_parent')->references(['id', 'document_id'])->on('sales_order_lines')->restrictOnDelete()->restrictOnUpdate();
        });
        $this->check('sales_order_lines', 'quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0 AND tax_rate_id IS NULL');
        $this->check('sales_dispatch_lines', 'base_quantity>0 AND net_revenue_amount>=0');
        $this->check('sales_invoices', 'net_amount>0 AND tax_amount=0 AND rounding_amount=0 AND gross_amount=net_amount');
        $this->check('sales_invoice_lines', 'quantity>0 AND base_quantity>0 AND conversion_factor>0 AND unit_price>=0 AND discount_amount>=0 AND net_amount>=0 AND tax_amount=0 AND gross_amount=net_amount');
        $this->check('sales_invoice_dispatch_matches', 'matched_base_quantity>0 AND net_amount>=0');
        $this->check('sales_return_lines', 'base_quantity>0');
        $this->check('sales_credit_notes', 'net_amount>0 AND tax_amount=0 AND rounding_amount=0 AND gross_amount=net_amount');
        $this->check('sales_credit_note_lines', 'credited_base_quantity>=0 AND net_amount>0 AND tax_amount=0 AND gross_amount=net_amount');
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $t) {
            $t->dropForeign('fk_s11_reservation_parent');
            $t->dropForeign('fk_s11_'.substr(sha1('stock_reservationssales_order_line_id'), 0, 18));
            $t->dropColumn('sales_order_line_id');
        });
        foreach (['sales_credit_note_lines', 'sales_credit_notes', 'sales_return_lines', 'sales_returns', 'sales_invoice_dispatch_matches', 'sales_invoice_lines', 'sales_invoices', 'sales_dispatch_lines', 'sales_dispatches', 'sales_order_lines', 'sales_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
