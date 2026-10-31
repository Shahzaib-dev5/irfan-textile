<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use Illuminate\Database\Seeder;

class SalesConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['sales_order' => ['Sales order', 'SO'], 'sales_dispatch' => ['Sales dispatch note', 'SD'], 'sales_invoice' => ['Sales invoice', 'SI'], 'sales_return' => ['Sales physical return', 'SR'], 'sales_credit_note' => ['Sales credit note', 'SCN']] as $code => $row) {
            DocumentType::firstOrCreate(['code' => $code], ['name' => $row[0], 'module_code' => 'sales', 'prefix' => $row[1]]);
        }
        foreach ([['1200', 'Customer receivables', 'asset', 'debit', 'party_receivable', 'customer_receivable'], ['1210', 'Unbilled customer receivables', 'asset', 'debit', 'none', 'unbilled_receivable'], ['4000', 'Wholesale sales revenue', 'income', 'credit', 'none', 'sales_revenue'], ['5000', 'Wholesale cost of goods sold', 'expense', 'debit', 'none', 'sales_cogs'], ['5010', 'Sales returns and allowances', 'expense', 'debit', 'none', 'sales_returns']] as $r) {
            $a = LedgerAccount::firstOrCreate(['code' => $r[0]], ['name' => $r[1], 'account_type' => $r[2], 'normal_balance' => $r[3], 'control_type' => $r[4]]);
            PostingAccount::firstOrCreate(['purpose_code' => $r[5]], ['ledger_account_id' => $a->id, 'description' => $r[1]]);
        }
    }
}
