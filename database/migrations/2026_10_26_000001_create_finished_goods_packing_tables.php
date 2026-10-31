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
        $t->index($column, 'idx_p10_'.$suffix);
        $t->foreign($column, 'fk_p10_'.$suffix)->references($key)->on($table)->restrictOnDelete()->restrictOnUpdate();
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
        Schema::create('product_pack_specs', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'packed_item_id', 'items');
            $t->unsignedInteger('version_number');
            $t->string('description', 200);
            $t->string('state', 20)->default('draft');
            $t->boolean('is_active')->default(true);
            $t->unique(['packed_item_id', 'version_number']);
            $t->index(['state', 'is_active']);
            $this->stamp($t);
        });
        Schema::create('product_pack_spec_lines', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'product_pack_spec_id', 'product_pack_specs');
            $t->unsignedSmallInteger('line_no');
            $this->fk($t, 'component_item_id', 'items');
            $t->string('component_kind', 20);
            $t->decimal('quantity_per_pack', 20, 6);
            $t->unique(['product_pack_spec_id', 'line_no']);
            $t->unique(['product_pack_spec_id', 'component_item_id'], 'uq_p10_spec_item');
            $this->stamp($t);
        });
        Schema::create('packing_orders', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'product_pack_spec_id', 'product_pack_specs');
            $t->string('operation', 20)->default('pack');
            $t->string('remarks', 1000)->nullable();
        });
        Schema::create('packing_material_lines', function (Blueprint $t) {
            $this->line($t, 'packing_orders');
            $this->fk($t, 'product_pack_spec_line_id', 'product_pack_spec_lines', 'id', true);
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('quantity', 20, 6);
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
        });
        Schema::create('packing_output_lines', function (Blueprint $t) {
            $this->line($t, 'packing_orders');
            $this->fk($t, 'stock_position_id', 'stock_positions');
            $t->decimal('quantity', 20, 6);
            $t->decimal('cost_allocation_fraction', 20, 10)->default(1);
            $this->fk($t, 'stock_movement_id', 'stock_movements', 'id', true);
            $t->unique('stock_movement_id');
        });
        Schema::create('packages', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'packing_output_line_id', 'packing_output_lines');
            $t->string('package_code', 80)->unique();
            $t->decimal('quantity', 20, 6);
            $t->decimal('gross_weight_kg', 12, 3)->nullable();
            $t->dateTime('sealed_at', 6)->nullable();
            $this->stamp($t);
        });
        Schema::create('package_contents', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'package_id', 'packages');
            $this->fk($t, 'packing_material_line_id', 'packing_material_lines');
            $t->decimal('quantity', 20, 6);
            $t->unique(['package_id', 'packing_material_line_id']);
            $this->stamp($t);
        });
        foreach ([
            'product_pack_specs' => "version_number>0 AND state IN ('draft','published','retired') AND is_active IN (0,1)",
            'product_pack_spec_lines' => "line_no>0 AND quantity_per_pack>0 AND component_kind IN ('saleable_content','packaging')",
            'packing_orders' => "operation IN ('pack','unpack')",
            'packing_material_lines' => 'line_no>0 AND quantity>0',
            'packing_output_lines' => 'line_no>0 AND quantity>0 AND cost_allocation_fraction>=0 AND cost_allocation_fraction<=1',
            'packages' => 'quantity>0 AND (gross_weight_kg IS NULL OR gross_weight_kg>=0)',
            'package_contents' => 'quantity>0',
        ] as $table => $expression) {
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT chk_p10_'.substr(sha1($table), 0, 16).' CHECK ('.$expression.')');
        }
    }

    public function down(): void
    {
        foreach (['package_contents', 'packages', 'packing_output_lines', 'packing_material_lines', 'packing_orders', 'product_pack_spec_lines', 'product_pack_specs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
