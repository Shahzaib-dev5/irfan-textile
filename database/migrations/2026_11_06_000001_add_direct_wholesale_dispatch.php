<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_dispatch_lines', function (Blueprint $table) {
            $table->dropForeign('fk_s11_'.substr(sha1('sales_dispatch_linessales_order_line_id'), 0, 18));
            $table->unsignedBigInteger('sales_order_line_id')->nullable()->change();
            $table->foreign('sales_order_line_id', 'fk_s12_dispatch_order_line')->references('id')->on('sales_order_lines')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('direct_item_id')->nullable()->constrained('items', 'id')->restrictOnUpdate()->restrictOnDelete();
            $table->decimal('direct_unit_price', 20, 8)->nullable();
            $table->decimal('direct_discount_amount', 20, 4)->nullable();
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropForeign('fk_s11_'.substr(sha1('sales_invoice_linessales_order_line_id'), 0, 18));
            $table->unsignedBigInteger('sales_order_line_id')->nullable()->change();
            $table->foreign('sales_order_line_id', 'fk_s12_invoice_order_line')->references('id')->on('sales_order_lines')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE sales_dispatch_lines ADD CONSTRAINT chk_s12_dispatch_source CHECK ((sales_order_line_id IS NOT NULL AND direct_item_id IS NULL AND direct_unit_price IS NULL AND direct_discount_amount IS NULL) OR (sales_order_line_id IS NULL AND direct_item_id IS NOT NULL AND direct_unit_price IS NOT NULL AND direct_unit_price>=0 AND direct_discount_amount IS NOT NULL AND direct_discount_amount>=0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_dispatch_lines DROP CHECK chk_s12_dispatch_source');
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropForeign('fk_s12_invoice_order_line');
            $table->unsignedBigInteger('sales_order_line_id')->nullable(false)->change();
            $table->foreign('sales_order_line_id', 'fk_s11_'.substr(sha1('sales_invoice_linessales_order_line_id'), 0, 18))->references('id')->on('sales_order_lines')->restrictOnUpdate()->restrictOnDelete();
        });
        Schema::table('sales_dispatch_lines', function (Blueprint $table) {
            $table->dropForeign('fk_s12_dispatch_order_line');
            $table->dropConstrainedForeignId('direct_item_id');
            $table->dropColumn(['direct_unit_price', 'direct_discount_amount']);
            $table->unsignedBigInteger('sales_order_line_id')->nullable(false)->change();
            $table->foreign('sales_order_line_id', 'fk_s11_'.substr(sha1('sales_dispatch_linessales_order_line_id'), 0, 18))->references('id')->on('sales_order_lines')->restrictOnUpdate()->restrictOnDelete();
        });
    }
};
