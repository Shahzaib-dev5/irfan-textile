<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use Illuminate\Database\Seeder;

class ProductionConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['production_batch' => ['Manufacturing batch', 'MB'], 'production_report' => ['Production report', 'PR']] as $code => $row) {
            DocumentType::firstOrCreate(['code' => $code], ['name' => $row[0], 'module_code' => 'production', 'prefix' => $row[1]]);
        }
        foreach ([['5130', 'Abnormal production loss', 'abnormal_production_loss'], ['5200', 'Production direct labor', 'production_labor'], ['5210', 'Production overhead', 'production_overhead'], ['5220', 'Other conversion expense', 'production_conversion']] as $r) {
            $account = LedgerAccount::firstOrCreate(['code' => $r[0]], ['name' => $r[1], 'account_type' => 'expense', 'normal_balance' => 'debit', 'control_type' => 'none']);
            PostingAccount::firstOrCreate(['purpose_code' => $r[2]], ['ledger_account_id' => $account->id, 'description' => $r[1]]);
        }
    }
}
