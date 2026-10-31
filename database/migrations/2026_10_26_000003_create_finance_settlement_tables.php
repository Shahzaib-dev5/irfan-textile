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
        $t->foreign($column)->references($key)->on($table)->restrictOnDelete()->restrictOnUpdate();
    }

    private function header(Blueprint $t): void
    {
        $this->fk($t, 'document_id', 'documents');
        $t->primary('document_id');
        $t->timestamps(6);
    }

    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 150);
            $t->enum('account_kind', ['bank', 'cash']);
            $this->fk($t, 'ledger_account_id', 'ledger_accounts');
            $t->unique('ledger_account_id');
            $t->string('bank_name', 150)->nullable();
            $t->string('branch_name', 150)->nullable();
            $t->string('account_reference', 100)->nullable();
            $t->string('iban', 34)->nullable()->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps(6);
        });
        foreach (['payments', 'receipts'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $this->header($t);
                $this->fk($t, 'party_id', 'parties');
                $this->fk($t, 'party_role_id', 'party_roles', 'id', true);
                $this->fk($t, 'bank_account_id', 'bank_accounts');
                $this->fk($t, 'settlement_account_id', 'ledger_accounts');
                $t->enum('method', ['cash', 'bank_transfer', 'cheque', 'other']);
                $t->decimal('gross_amount', 20, 4);
                $t->decimal('bank_amount', 20, 4);
                $t->string('reference', 100)->nullable();
                $t->date('reference_date')->nullable();
                $t->string('description', 500)->nullable();
                $t->index(['party_id', 'document_id']);
                $t->index(['bank_account_id', 'reference']);
                $t->foreign(['party_role_id', 'party_id'])->references(['id', 'party_id'])->on('party_roles')->restrictOnDelete()->restrictOnUpdate();
            });
            DB::statement("ALTER TABLE $table ADD CONSTRAINT ck_{$table}_amount CHECK(gross_amount > 0 AND bank_amount = gross_amount)");
        }
        Schema::create('settlement_batches', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'party_id', 'parties');
            $this->fk($t, 'ledger_account_id', 'ledger_accounts');
            $t->enum('settlement_kind', ['apply', 'reverse']);
            $t->string('description', 500)->nullable();
            $t->index(['party_id', 'ledger_account_id', 'document_id']);
        });
        Schema::create('settlement_allocations', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'document_id', 'settlement_batches', 'document_id');
            $t->unsignedSmallInteger('line_no');
            $this->fk($t, 'debit_open_item_id', 'party_open_items');
            $this->fk($t, 'credit_open_item_id', 'party_open_items');
            $t->decimal('amount', 20, 4);
            $this->fk($t, 'reverses_allocation_id', 'settlement_allocations', 'id', true);
            $t->unique('reverses_allocation_id');
            $t->unique(['document_id', 'line_no']);
            $t->index(['debit_open_item_id', 'id']);
            $t->index(['credit_open_item_id', 'id']);
            $t->timestamp('created_at', 6)->nullable();
        });
        Schema::create('bank_statements', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'bank_account_id', 'bank_accounts');
            $t->string('statement_reference', 100);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->decimal('opening_balance', 20, 4);
            $t->decimal('closing_balance', 20, 4);
            $t->char('import_sha256', 64)->nullable();
            $this->fk($t, 'imported_by', 'users');
            $t->dateTime('confirmed_at', 6)->nullable();
            $t->timestamps(6);
            $t->unique(['bank_account_id', 'statement_reference']);
            $t->unique(['bank_account_id', 'import_sha256']);
            $t->index(['bank_account_id', 'starts_on', 'ends_on']);
        });
        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'bank_statement_id', 'bank_statements');
            $t->unsignedInteger('line_no');
            $t->date('transaction_on');
            $t->date('value_on')->nullable();
            $t->string('reference', 150)->nullable();
            $t->string('description', 500);
            $t->decimal('amount', 20, 4);
            $t->string('external_transaction_key', 150)->nullable();
            $t->unique(['bank_statement_id', 'line_no']);
            $t->unique(['bank_statement_id', 'external_transaction_key'], 'uq_statement_external');
            $t->index(['transaction_on', 'reference']);
            $t->timestamps(6);
        });
        Schema::create('bank_reconciliations', function (Blueprint $t) {
            $this->header($t);
            $this->fk($t, 'bank_account_id', 'bank_accounts');
            $t->date('as_of');
            foreach (['statement_closing_balance', 'book_closing_balance', 'unpresented_outflow', 'uncredited_inflow', 'other_reconciling_amount'] as $column) {
                $t->decimal($column, 20, 4)->default(0);
            }
            $t->string('explanation', 1000)->nullable();
            $t->index(['bank_account_id', 'as_of']);
        });
        Schema::create('bank_reconciliation_matches', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'document_id', 'bank_reconciliations', 'document_id');
            $t->unsignedSmallInteger('line_no');
            $this->fk($t, 'bank_statement_line_id', 'bank_statement_lines');
            $this->fk($t, 'journal_line_id', 'journal_lines');
            $t->decimal('matched_amount', 20, 4);
            $this->fk($t, 'reverses_match_id', 'bank_reconciliation_matches', 'id', true);
            $t->unique('reverses_match_id');
            $t->unique(['document_id', 'line_no']);
            $t->index(['bank_statement_line_id', 'id']);
            $t->index(['journal_line_id', 'id']);
            $t->timestamps(6);
        });
        foreach ([
            'bank_accounts' => "is_active IN (0,1) AND (account_kind='bank' OR (bank_name IS NULL AND branch_name IS NULL AND iban IS NULL))",
            'settlement_allocations' => 'amount>0 AND line_no>0 AND debit_open_item_id<>credit_open_item_id',
            'bank_statements' => 'ends_on>=starts_on', 'bank_statement_lines' => 'amount<>0 AND line_no>0',
            'bank_reconciliations' => 'unpresented_outflow>=0 AND uncredited_inflow>=0 AND other_reconciling_amount=0 AND book_closing_balance=statement_closing_balance-unpresented_outflow+uncredited_inflow',
            'bank_reconciliation_matches' => 'matched_amount>0 AND line_no>0',
        ] as $table => $check) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT ck_{$table}_valid CHECK ($check)");
        }
    }

    public function down(): void
    {
        throw new LogicException('Financial history requires a reviewed forward migration.');
    }
};
