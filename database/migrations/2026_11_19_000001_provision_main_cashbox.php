<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $usable = DB::table('bank_accounts')
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'bank_accounts.ledger_account_id')
                ->where('bank_accounts.account_kind', 'cash')
                ->where('bank_accounts.is_active', true)
                ->where('ledger_accounts.is_active', true)
                ->where('ledger_accounts.is_postable', true)
                ->where('ledger_accounts.control_type', 'bank_cash')
                ->where('ledger_accounts.account_type', 'asset');

            if ($usable->exists()) {
                return;
            }

            $code = 'MAIN-CASH';
            for ($suffix = 2; DB::table('ledger_accounts')->where('code', $code)->exists()
                || DB::table('bank_accounts')->where('code', $code)->exists(); $suffix++) {
                $code = 'MAIN-CASH-'.$suffix;
            }

            $timestamps = ['created_at' => now(), 'updated_at' => now()];
            $ledgerId = DB::table('ledger_accounts')->insertGetId($timestamps + [
                'code' => $code,
                'name' => 'Main Cashbox',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'control_type' => 'bank_cash',
            ]);
            DB::table('bank_accounts')->insert($timestamps + [
                'code' => $code,
                'name' => 'Main Cashbox',
                'account_kind' => 'cash',
                'ledger_account_id' => $ledgerId,
            ]);
        });
    }

    public function down(): void
    {
        // Keep account identities available to any payments posted since setup.
    }
};
