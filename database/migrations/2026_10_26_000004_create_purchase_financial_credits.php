<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_credit_notes', function (Blueprint $t) {
            $t->unsignedBigInteger('document_id')->primary();
            $t->foreign('document_id')->references('id')->on('documents')->restrictOnDelete()->restrictOnUpdate();
            $t->unsignedBigInteger('purchase_invoice_id');
            $t->foreign('purchase_invoice_id')->references('document_id')->on('purchase_invoices')->restrictOnDelete()->restrictOnUpdate();
            $t->string('supplier_credit_number', 100);
            $t->enum('credit_kind', ['physical_return', 'price_adjustment', 'service_adjustment']);
            $t->string('reason', 500);
            foreach (['net_amount', 'tax_amount', 'rounding_amount', 'gross_amount'] as $c) {
                $t->decimal($c, 20, 4)->default(0);
            }
            $t->unique(['purchase_invoice_id', 'supplier_credit_number'], 'uq_purchase_credit_reference');
            $t->timestamps(6);
        });
        Schema::create('purchase_credit_note_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('document_id');
            $t->foreign('document_id')->references('document_id')->on('purchase_credit_notes')->restrictOnDelete()->restrictOnUpdate();
            $t->unsignedSmallInteger('line_no');
            $t->unique(['document_id', 'line_no']);
            foreach (['purchase_invoice_line_id' => 'purchase_invoice_lines', 'purchase_return_line_id' => 'purchase_return_lines', 'purchase_invoice_receipt_match_id' => 'purchase_invoice_receipt_matches', 'purchase_invoice_processing_match_id' => 'purchase_invoice_processing_matches'] as $c => $table) {
                $t->unsignedBigInteger($c)->nullable($c !== 'purchase_invoice_line_id');
                $t->foreign($c, 'fk_pcn_'.substr(hash('sha256', $c), 0, 12))->references('id')->on($table)->restrictOnDelete()->restrictOnUpdate();
            }
            $t->decimal('credited_quantity', 20, 6)->default(0);
            foreach (['net_amount', 'tax_amount', 'gross_amount'] as $c) {
                $t->decimal($c, 20, 4)->default(0);
            }$t->string('description', 255);
            $t->timestamps(6);
        });
        DB::statement("ALTER TABLE purchase_credit_notes ADD CONSTRAINT ck_purchase_credit_amount CHECK(net_amount>0 AND tax_amount=0 AND rounding_amount=0 AND gross_amount=net_amount AND credit_kind='physical_return')");
        DB::statement('ALTER TABLE purchase_credit_note_lines ADD CONSTRAINT ck_purchase_credit_line CHECK(credited_quantity>0 AND net_amount>0 AND tax_amount=0 AND gross_amount=net_amount AND purchase_return_line_id IS NOT NULL AND purchase_invoice_receipt_match_id IS NOT NULL AND purchase_invoice_processing_match_id IS NULL)');
    }

    public function down(): void
    {
        throw new LogicException('Retain posted supplier credits; use forward migrations.');
    }
};
