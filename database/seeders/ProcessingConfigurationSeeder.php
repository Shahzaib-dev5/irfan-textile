<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use Illuminate\Database\Seeder;

class ProcessingConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['processing_job' => ['Processing job', 'PJOB'], 'processing_dispatch' => ['Processing dispatch challan', 'PDC'], 'processing_receipt' => ['Processing receipt / return', 'PRC'], 'processing_bill' => ['Contractor bill', 'PCB']] as $code => $row) {
            DocumentType::firstOrCreate(['code' => $code], ['name' => $row[0], 'module_code' => 'processing', 'prefix' => $row[1]]);
        }
        foreach ([['2120', 'Processing accrual', 'liability', 'credit', 'processing_accrual'], ['5120', 'Abnormal processing loss', 'expense', 'debit', 'abnormal_processing_loss']] as $r) {
            $a = LedgerAccount::firstOrCreate(['code' => $r[0]], ['name' => $r[1], 'account_type' => $r[2], 'normal_balance' => $r[3], 'control_type' => 'none']);
            PostingAccount::firstOrCreate(['purpose_code' => $r[4]], ['ledger_account_id' => $a->id, 'description' => $r[1]]);
        }
    }
}
