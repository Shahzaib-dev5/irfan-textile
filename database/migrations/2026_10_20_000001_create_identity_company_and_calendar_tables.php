<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->char('code', 3)->primary();
            $table->string('name', 100);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
        });
        DB::statement("ALTER TABLE currencies ADD CONSTRAINT chk_currencies_pkr_only CHECK (code = 'PKR')");
        DB::statement('ALTER TABLE currencies ADD CONSTRAINT chk_currencies_active CHECK (is_active IN (0, 1))');
        DB::statement('ALTER TABLE currencies ADD CONSTRAINT chk_currencies_decimals CHECK (decimal_places BETWEEN 0 AND 4)');
        DB::statement('ALTER TABLE users MODIFY created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), MODIFY updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT chk_users_active CHECK (is_active IN (0, 1))');

        Schema::create('companies', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->default(1)->primary();
            $table->string('legal_name', 200);
            $table->string('trading_name', 200)->nullable();
            $table->string('ntn', 30)->nullable();
            $table->string('sales_tax_registration', 40)->nullable();
            $table->text('address_text')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 254)->nullable();
            $table->char('functional_currency', 3)->default('PKR');
            $table->string('timezone', 64)->default('Asia/Karachi');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(7);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->foreign('functional_currency', 'fk_companies_currency')
                ->references('code')->on('currencies')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE companies ADD CONSTRAINT chk_companies_singleton CHECK (id = 1)');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT chk_companies_currency CHECK (functional_currency = 'PKR')");
        DB::statement('ALTER TABLE companies ADD CONSTRAINT chk_companies_fiscal_month CHECK (fiscal_year_start_month BETWEEN 1 AND 12)');

        Schema::create('company_settings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('setting_key', 100)->unique('uq_company_settings_key');
            $table->json('value_json');
            $table->unsignedBigInteger('updated_by');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->foreign('updated_by', 'fk_company_settings_users')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('access_roles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 50)->unique('uq_access_roles_code');
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->index(['is_active', 'name'], 'idx_access_roles_active_name');
        });
        DB::statement('ALTER TABLE access_roles ADD CONSTRAINT chk_access_roles_active CHECK (is_active IN (0, 1))');

        Schema::create('permissions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 100)->unique('uq_permissions_code');
            $table->string('module_code', 40);
            $table->string('description', 255);
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->index('module_code', 'idx_permissions_module');
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('access_role_id');
            $table->unsignedBigInteger('assigned_by');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->unique(['user_id', 'access_role_id'], 'uq_user_roles_user_role');
            $table->index('access_role_id', 'idx_user_roles_role');
            $table->index('assigned_by', 'idx_user_roles_assigned_by');
            $table->foreign('user_id', 'fk_user_roles_user')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('access_role_id', 'fk_user_roles_role')->references('id')->on('access_roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('assigned_by', 'fk_user_roles_assigner')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('access_role_id');
            $table->unsignedBigInteger('permission_id');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->unique(['access_role_id', 'permission_id'], 'uq_role_permissions_role_permission');
            $table->index('permission_id', 'idx_role_permissions_permission');
            $table->foreign('access_role_id', 'fk_role_permissions_role')->references('id')->on('access_roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('permission_id', 'fk_role_permissions_permission')->references('id')->on('permissions')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('financial_years', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('code', 20)->unique('uq_financial_years_code');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_closed')->default(false);
            $table->dateTime('closed_at', 6)->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->index(['starts_on', 'ends_on'], 'idx_financial_years_dates');
            $table->index('closed_by', 'idx_financial_years_closed_by');
            $table->foreign('closed_by', 'fk_financial_years_closer')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE financial_years ADD CONSTRAINT chk_financial_years_dates CHECK (ends_on >= starts_on)');
        DB::statement('ALTER TABLE financial_years ADD CONSTRAINT chk_financial_years_closed CHECK ((is_closed = 0 AND closed_at IS NULL AND closed_by IS NULL) OR (is_closed = 1 AND closed_at IS NOT NULL AND closed_by IS NOT NULL))');

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('financial_year_id');
            $table->string('code', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 10)->default('open');
            $table->dateTime('closed_at', 6)->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
            $table->unique(['financial_year_id', 'code'], 'uq_periods_year_code');
            $table->unique(['id', 'financial_year_id'], 'uq_periods_id_year');
            $table->index(['financial_year_id', 'starts_on', 'ends_on'], 'idx_periods_year_dates');
            $table->index('closed_by', 'idx_periods_closed_by');
            $table->foreign('financial_year_id', 'fk_periods_financial_year')->references('id')->on('financial_years')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('closed_by', 'fk_periods_closer')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT chk_periods_dates CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT chk_periods_status CHECK ((status = 'open' AND closed_at IS NULL AND closed_by IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL))");

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('entity_table', 64);
            $table->string('entity_key', 100);
            $table->string('action', 40);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->char('request_key', 36);
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->index(['entity_table', 'entity_key', 'created_at'], 'idx_audit_entity_created');
            $table->index(['document_id', 'created_at'], 'idx_audit_document_created');
            $table->index(['actor_id', 'created_at'], 'idx_audit_actor_created');
            $table->index('request_key', 'idx_audit_request_key');
            $table->foreign('actor_id', 'fk_audit_events_actor')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        throw new LogicException('Phase 3 setup and audit records are retained. Use a reviewed, backed-up data migration; automatic rollback is disabled.');
    }
};
