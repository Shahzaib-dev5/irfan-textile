<?php

namespace Database\Seeders;

use App\Models\DocumentSequence;
use App\Models\DocumentType;
use App\Models\FinancialYear;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use Illuminate\Database\Seeder;

class PurchasingConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['purchase_order' => ['Purchase order', 'PO'], 'goods_receipt' => ['Goods receipt note', 'GRN'], 'purchase_return' => ['Purchase return', 'PRTN'], 'purchase_invoice' => ['Supplier bill', 'BILL']] as $code => $definition) {
            $type = DocumentType::firstOrCreate(['code' => $code], ['name' => $definition[0], 'module_code' => 'purchasing', 'prefix' => $definition[1]]);
            foreach (FinancialYear::pluck('id') as $year) {
                DocumentSequence::firstOrCreate(['document_type_id' => $type->id, 'financial_year_id' => $year], ['next_value' => 1]);
            }
        }
        foreach ([['2100', 'Goods received not invoiced', 'liability', 'credit', 'none', 'grni'], ['2110', 'Supplier payable', 'liability', 'credit', 'party_payable', 'supplier_payable'], ['1190', 'Supplier return clearing', 'asset', 'debit', 'none', 'supplier_return_clearing']] as $row) {
            $account = LedgerAccount::firstOrCreate(['code' => $row[0]], ['name' => $row[1], 'account_type' => $row[2], 'normal_balance' => $row[3], 'control_type' => $row[4]]);
            PostingAccount::firstOrCreate(['purpose_code' => $row[5]], ['ledger_account_id' => $account->id, 'description' => $row[1]]);
        }
    }
}
