<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_fabric_flows', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('kind', 3);
            $table->string('status', 10)->default('draft');
            $table->char('input_hash', 64);
            $table->json('details');
            $table->foreignId('document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('onward_document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps(6);
        });
        DB::statement("ALTER TABLE processing_fabric_flows ADD CONSTRAINT chk_fabric_flow_kind CHECK (kind IN ('out','in')), ADD CONSTRAINT chk_fabric_flow_status CHECK ((status = 'draft' AND document_id IS NULL AND onward_document_id IS NULL) OR (status = 'posted' AND document_id IS NOT NULL)), ADD CONSTRAINT chk_fabric_flow_onward CHECK (onward_document_id IS NULL OR (kind = 'in' AND onward_document_id <> document_id))");
    }

    public function down(): void
    {
        throw new RuntimeException('Fabric flow history is forward-only. Restore an approved backup instead.');
    }
};
