<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 40)->unique('uq_item_categories_code');
            $table->string('name', 100);
            $this->master($table);
            $table->index(['parent_id', 'is_active'], 'idx_item_categories_parent_active');
            $table->foreign('parent_id')->references('id')->on('item_categories')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('items', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('sku', 60)->unique('uq_items_sku');
            $table->string('name', 200);
            $table->string('item_type', 30);
            $table->unsignedBigInteger('base_unit_id')->index('idx_items_base_unit');
            $table->boolean('is_stocked')->default(true);
            $table->boolean('track_rolls')->default(false);
            $table->text('description')->nullable();
            $this->master($table);
            $table->index(['category_id', 'item_type', 'is_active'], 'idx_items_category_type_active');
            $table->index(['name', 'is_active'], 'idx_items_name_active');
            $table->index(['item_type', 'is_active'], 'idx_items_type_active');
            $table->foreign('category_id')->references('id')->on('item_categories')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('base_unit_id')->references('id')->on('units')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('item_unit_conversions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('unit_id')->index('idx_item_conversions_unit');
            $table->decimal('factor_to_base', 20, 10);
            $this->timestamps($table);
            $table->unique(['item_id', 'unit_id'], 'uq_item_conversions_item_unit');
            $table->foreign('item_id')->references('id')->on('items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('unit_id')->references('id')->on('units')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('fabric_specifications', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('item_id')->primary();
            $table->decimal('width_mm', 12, 3)->nullable();
            $table->decimal('gsm', 12, 3)->nullable();
            $table->string('composition', 200);
            $table->string('weave', 100)->nullable();
            $this->timestamps($table);
            $table->foreign('item_id')->references('id')->on('items')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('designs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 50)->unique('uq_designs_code');
            $table->string('name', 150);
            $table->string('season', 60)->nullable();
            $table->text('description')->nullable();
            $this->master($table);
            $table->index(['season', 'is_active'], 'idx_designs_season_active');
            $table->index('name', 'idx_designs_name');
        });
        Schema::create('colors', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 40)->unique('uq_colors_code');
            $table->string('name', 100);
            $table->char('hex_code', 7)->nullable();
            $this->master($table);
            $table->index('name', 'idx_colors_name');
        });
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('item_id')->unique('uq_product_variants_item');
            $table->unsignedBigInteger('design_id');
            $table->unsignedBigInteger('color_id')->index('idx_product_variants_color');
            $table->string('configuration_code', 40);
            $table->unsignedTinyInteger('piece_count');
            $table->string('description', 500)->nullable();
            $this->master($table);
            $table->unique(['design_id', 'color_id', 'configuration_code'], 'uq_product_variants_design_color_config');
            $table->foreign('item_id')->references('id')->on('items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('design_id')->references('id')->on('designs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('color_id')->references('id')->on('colors')->restrictOnDelete()->restrictOnUpdate();
        });
        foreach (['item_categories', 'items', 'designs', 'colors', 'product_variants'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chk_{$table}_active CHECK (is_active IN (0,1))");
        }
        DB::statement("ALTER TABLE items ADD CONSTRAINT chk_items_type CHECK (item_type IN ('grey_fabric','processed_fabric','trim','packaging','finished','scrap','service'))");
        DB::statement('ALTER TABLE items ADD CONSTRAINT chk_items_flags CHECK (is_stocked IN (0,1) AND track_rolls IN (0,1))');
        DB::statement("ALTER TABLE items ADD CONSTRAINT chk_items_service CHECK (item_type <> 'service' OR (is_stocked = 0 AND track_rolls = 0))");
        DB::statement('ALTER TABLE item_unit_conversions ADD CONSTRAINT chk_item_conversions_factor CHECK (factor_to_base > 0)');
        DB::statement('ALTER TABLE fabric_specifications ADD CONSTRAINT chk_fabric_dimensions CHECK ((width_mm IS NULL OR width_mm > 0) AND (gsm IS NULL OR gsm > 0))');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_product_variants_pieces CHECK (piece_count > 0)');
    }

    private function timestamps(Blueprint $table): void
    {
        $table->dateTime('created_at', 6)->useCurrent();
        $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
    }

    private function master(Blueprint $table): void
    {
        $table->boolean('is_active')->default(true);
        $this->timestamps($table);
    }

    public function down(): void
    {
        throw new LogicException('Catalog definitions are protected. Use a reviewed forward migration.');
    }
};
