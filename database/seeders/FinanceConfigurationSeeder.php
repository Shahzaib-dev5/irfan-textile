<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class FinanceConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['finance_supplier_credit' => ['Supplier return credit', 'PCN'], 'finance_cash_journal' => ['Cash / bank journal', 'CBJ'], 'finance_journal' => ['Journal voucher', 'JV'], 'finance_opening' => ['Opening balance voucher', 'OB'], 'finance_payment' => ['Payment voucher', 'PV'], 'finance_receipt' => ['Receipt voucher', 'RV'], 'finance_settlement' => ['Settlement allocation', 'SA'], 'finance_reversal' => ['Financial reversal', 'FR'], 'finance_reconciliation' => ['Bank reconciliation', 'BR']] as $code => $row) {
            DocumentType::firstOrCreate(['code' => $code], ['name' => $row[0], 'module_code' => 'accounting', 'prefix' => $row[1]]);
        }
    }
}
