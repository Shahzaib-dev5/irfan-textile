<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 20)->unique('uq_units_code');
            $table->string('name', 60);
            $table->string('dimension', 20);
            $table->unsignedTinyInteger('decimal_places')->default(6);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->index(['is_active', 'name'], 'idx_units_active_name');
        });
        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_dimension CHECK (dimension IN ('length','mass','count','area','service'))");
        DB::statement('ALTER TABLE units ADD CONSTRAINT chk_units_precision CHECK (decimal_places <= 6)');
        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 30)->unique('uq_payment_terms_code');
            $table->string('name', 100);
            $table->unsignedSmallInteger('due_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->index(['is_active', 'name'], 'idx_payment_terms_active_name');
        });
        Schema::create('parties', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 40)->unique('uq_parties_code');
            $table->string('legal_name', 200);
            $table->string('trading_name', 200)->nullable();
            $table->string('party_kind', 20)->default('organization');
            $table->string('ntn', 30)->nullable()->unique('uq_parties_ntn');
            $table->string('sales_tax_registration', 40)->nullable()->unique('uq_parties_sales_tax');
            $table->string('cnic', 25)->nullable()->unique('uq_parties_cnic');
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->index(['legal_name', 'is_active'], 'idx_parties_name_active');
            $table->index('phone', 'idx_parties_phone');
            $table->index('email', 'idx_parties_email');
        });
        Schema::create('party_addresses', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('party_id')->constrained('parties', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->string('label', 60);
            $table->string('address_type', 20)->default('business');
            $table->string('address_line_1', 255);
            $table->string('address_line_2', 255)->nullable();
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->default('PK');
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unique(['party_id', 'label'], 'uq_party_addresses_party_label');
            $table->index(['party_id', 'address_type', 'is_active'], 'idx_party_addresses_type_active');
        });
        Schema::create('party_contacts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('party_id')->constrained('parties', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name', 150);
            $table->string('designation', 100)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->index(['party_id', 'name'], 'idx_party_contacts_party_name');
        });
        Schema::create('party_role_types', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 20)->unique('uq_party_role_types_code');
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->index(['is_active', 'name'], 'idx_party_role_types_active_name');
        });
        Schema::create('party_roles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('party_id')->constrained('parties', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('party_role_type_id')->constrained('party_role_types', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unique(['party_id', 'party_role_type_id'], 'uq_party_roles_party_type');
            $table->unique(['id', 'party_id'], 'uq_party_roles_id_party');
            $table->index(['party_role_type_id', 'is_active'], 'idx_party_roles_type_active');
        });
        Schema::create('supplier_profiles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('party_role_id')->primary();
            $table->foreignId('payment_term_id')->constrained('payment_terms', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->string('purchase_notes', 1000)->nullable();
            $table->timestamps(6);
            $table->index('payment_term_id', 'idx_supplier_profiles_payment_term');
            $table->foreign('party_role_id', 'fk_supplier_profiles_role')->references('id')->on('party_roles')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('vendor_profiles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('party_role_id')->primary();
            $table->foreignId('payment_term_id')->constrained('payment_terms', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->string('contractor_notes', 1000)->nullable();
            $table->timestamps(6);
            $table->index('payment_term_id', 'idx_vendor_profiles_payment_term');
            $table->foreign('party_role_id', 'fk_vendor_profiles_role')->references('id')->on('party_roles')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('party_role_id')->primary();
            $table->foreignId('payment_term_id')->constrained('payment_terms', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('credit_limit', 20, 4)->default(0);
            $table->boolean('credit_hold')->default(false);
            $table->string('sales_notes', 1000)->nullable();
            $table->timestamps(6);
            $table->index('payment_term_id', 'idx_customer_profiles_payment_term');
            $table->foreign('party_role_id', 'fk_customer_profiles_role')->references('id')->on('party_roles')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('processing_services', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 40)->unique('uq_processing_services_code');
            $table->string('name', 100);
            $table->foreignId('billing_unit_id')->constrained('units', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->index(['is_active', 'name'], 'idx_processing_services_active_name');
            $table->index('billing_unit_id', 'idx_processing_services_billing_unit');
        });
        Schema::create('vendor_services', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('vendor_party_role_id');
            $table->foreignId('processing_service_id')->constrained('processing_services', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unique(['vendor_party_role_id', 'processing_service_id'], 'uq_vendor_services_vendor_service');
            $table->foreign('vendor_party_role_id', 'fk_vendor_services_vendor')->references('party_role_id')->on('vendor_profiles')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['processing_service_id', 'is_active'], 'idx_vendor_services_service_active');
        });
        Schema::create('vendor_service_rates', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('vendor_service_id')->constrained('vendor_services', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('billing_unit_id')->constrained('units', 'id')->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('unit_rate', 20, 8);
            $table->char('currency_code', 3)->default('PKR');
            $table->string('contract_reference', 100)->nullable();
            $table->string('rate_reason', 500);
            $table->unsignedBigInteger('approved_by');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->unique(['vendor_service_id', 'effective_from'], 'uq_vendor_rates_service_from');
            $table->index(['vendor_service_id', 'effective_from', 'effective_to'], 'idx_vendor_rates_period');
            $table->index('billing_unit_id', 'idx_vendor_rates_billing_unit');
            $table->index('approved_by', 'idx_vendor_rates_approver');
            $table->index('currency_code', 'idx_vendor_rates_currency');
            $table->foreign('approved_by', 'fk_vendor_rates_approver')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('currency_code', 'fk_vendor_rates_currency')->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE parties ADD CONSTRAINT chk_parties_kind CHECK (party_kind IN ('organization','individual'))");
        DB::statement("ALTER TABLE party_addresses ADD CONSTRAINT chk_party_addresses_type CHECK (address_type IN ('business','billing','shipping'))");
        DB::statement('ALTER TABLE customer_profiles ADD CONSTRAINT chk_customer_profiles_credit CHECK (credit_limit >= 0)');
        DB::statement('ALTER TABLE vendor_service_rates ADD CONSTRAINT chk_vendor_rates_period CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement('ALTER TABLE vendor_service_rates ADD CONSTRAINT chk_vendor_rates_amount CHECK (unit_rate >= 0)');
        DB::statement("ALTER TABLE vendor_service_rates ADD CONSTRAINT chk_vendor_rates_pkr CHECK (currency_code = 'PKR')");
        DB::statement('ALTER TABLE party_roles ADD CONSTRAINT chk_party_roles_active CHECK (is_active IN (0,1))');
        DB::statement('ALTER TABLE party_contacts ADD CONSTRAINT chk_party_contacts_active CHECK (is_active IN (0,1))');
        DB::statement('ALTER TABLE party_addresses ADD CONSTRAINT chk_party_addresses_active CHECK (is_active IN (0,1))');
        DB::statement('ALTER TABLE vendor_services ADD CONSTRAINT chk_vendor_services_active CHECK (is_active IN (0,1))');
        DB::statement('ALTER TABLE customer_profiles ADD CONSTRAINT chk_customer_credit_hold CHECK (credit_hold IN (0,1))');
        DB::statement('ALTER TABLE units ADD CONSTRAINT chk_units_active CHECK (is_active IN (0,1))');
        DB::statement('ALTER TABLE payment_terms ADD CONSTRAINT chk_payment_terms_active CHECK (is_active IN (0,1))');
        DB::statement('ALTER TABLE parties ADD CONSTRAINT chk_parties_active CHECK (is_active IN (0,1))');
        DB::statement('ALTER TABLE party_role_types ADD CONSTRAINT chk_party_role_types_active CHECK (is_active IN (0,1))');
        DB::statement('ALTER TABLE processing_services ADD CONSTRAINT chk_processing_services_active CHECK (is_active IN (0,1))');
    }

    public function down(): void
    {
        throw new LogicException('Party, role and published rate history is protected; forward-only migration.');
    }
};
