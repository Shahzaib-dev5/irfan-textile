<?php

namespace Database\Seeders;

use App\Models\DocumentSequence;
use App\Models\DocumentType;
use App\Models\FinancialYear;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use Illuminate\Database\Seeder;

class InventoryConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['inventory_adjustment' => ['Inventory adjustment', 'IADJ'], 'inventory_transfer' => ['Inventory transfer', 'ITRF']] as $code => $definition) {
            $type = DocumentType::firstOrCreate(['code' => $code], ['name' => $definition[0], 'module_code' => 'inventory', 'prefix' => $definition[1]]);
            foreach (FinancialYear::pluck('id') as $year) {
                DocumentSequence::firstOrCreate(['document_type_id' => $type->id, 'financial_year_id' => $year], ['next_value' => 1]);
            }
        }
        foreach ([['1100', 'Inventory control', 'asset', 'debit', 'inventory', 'inventory'], ['3000', 'Opening stock equity', 'equity', 'credit', 'none', 'opening_equity'], ['5100', 'Inventory variance', 'expense', 'debit', 'none', 'inventory_variance']] as $row) {
            $account = LedgerAccount::firstOrCreate(['code' => $row[0]], ['name' => $row[1], 'account_type' => $row[2], 'normal_balance' => $row[3], 'control_type' => $row[4]]);
            PostingAccount::firstOrCreate(['purpose_code' => $row[5]], ['ledger_account_id' => $account->id, 'description' => $row[1]]);
        }
    }
}
