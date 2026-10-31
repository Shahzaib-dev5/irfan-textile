<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FinancialYear;
use App\Models\JournalLine;
use App\Models\ProductionOutputLine;
use App\Models\ProductionReport;
use App\Models\StockCostLayer;
use App\Modules\Accounting\Services\SourceJournal;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProductionFixtures;
use Tests\TestCase;

class ProductionCostTest extends TestCase
{
    use ProductionFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productionFixtures();
    }

    private function source(): JournalLine
    {
        $type = DocumentType::firstOrCreate(['code' => 'test_actual_cost'], ['name' => 'Existing recognized production expense', 'module_code' => 'accounting', 'prefix' => 'EXP']);
        $doc = Document::create(['document_type_id' => $type->id, 'document_date' => $this->date, 'financial_year_id' => FinancialYear::first()->id, 'accounting_period_id' => AccountingPeriod::first()->id, 'status' => 'draft', 'created_by' => $this->admin->id, 'idempotency_key' => (string) Str::uuid(), 'lock_version' => 0]);
        $documents = app(PurchaseDocuments::class);
        $labor = $documents->account('production_labor');
        $offset = $documents->account('opening_equity');
        DB::transaction(function () use ($doc, $documents, $labor, $offset) {
            app(SourceJournal::class)->post($doc, [$labor => '500', $offset => '-500']);
            $documents->finish($doc, $this->admin->id, (string) Str::uuid(), hash('sha256', 'existing recognized expense', true));
        });

        return JournalLine::where('journal_entry_id', $doc->id)->where('debit', '>', 0)->firstOrFail();
    }

    public function test_actual_labor_absorbs_once_and_source_cap_is_rechecked_on_post(): void
    {
        $p = $this->position($this->opening('600'));
        $batch = $this->batch();
        $source = $this->source();
        $a = $this->reportData($batch, $p);
        $a['costs'] = [['source_journal_line_id' => $source->id, 'output_index' => 0, 'cost_category' => 'direct_labor', 'amount_pkr' => '500']];
        $doc = $this->production->confirm($this->admin->id, $a);
        $o = ProductionOutputLine::where('document_id', $doc->id)->first();
        $layer = StockCostLayer::where('receipt_stock_movement_id', $o->stock_movement_id)->first();
        $this->assertSame('3500.0000', bcadd((string) $layer->values()->sum('amount_pkr'), '0', 4));
        $this->assertSame('500.0000', $doc->journal->total_debit);
        $a['idempotency_key'] = (string) Str::uuid();
        $a['outputs'][0]['lot_number'] = 'COST-SECOND';
        try {
            $this->production->confirm($this->admin->id, $a);
            $this->fail('Should cap the source.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already been absorbed', $e->getMessage());
        }
        $this->assertSame(1, ProductionReport::count());
        $this->assertSame('300.000000', app(StockLedger::class)->onHand($p));
    }
}
