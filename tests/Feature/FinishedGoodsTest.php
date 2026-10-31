<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InventoryCostLink;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\Package;
use App\Models\PackageContent;
use App\Models\PackingOrder;
use App\Models\PackingOutputLine;
use App\Models\ProductPackSpecLine;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Modules\Accounting\Services\SourceJournal;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\FinishedGoods\Services\ActualCosting;
use App\Modules\FinishedGoods\Services\PackSpecificationService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinishedGoodsFixtures;
use Tests\TestCase;

class FinishedGoodsTest extends TestCase
{
    use FinishedGoodsFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finishedGoodsFixtures();
    }

    private function rejected(callable $run, string $message): void
    {
        try {
            $run();
            $this->fail('Expected rejection.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_packing_consumes_suits_and_supplies_once_with_exact_cost_and_source_trace(): void
    {
        $data = $this->packingData();
        $doc = $this->packing->confirm($this->admin->id, $data);
        $out = PackingOutputLine::where('document_id', $doc->id)->first();
        $this->assertSame('90.000000', app(StockLedger::class)->onHand($this->suitPosition));
        $this->assertSame('10.000000', app(StockLedger::class)->onHand($out->stock_position_id));
        $cost = app(ActualCosting::class);
        $report = $cost->report($doc);
        $this->assertSame('620.0000', $report['total']);
        $this->assertSame('62.0000', $report['rows'][0]['per_suit']);
        $this->assertCount(2, $report['all']);
        $trace = $cost->ancestry($report['rows'][0]['layer']->id);
        $this->assertContains($this->finishedBatch->id, $trace['batches']);
        $this->assertSame(1, Package::count());
        $this->assertSame('20.000000', bcadd((string) PackageContent::sum('quantity'), '0', 6));
        $count = StockMovement::count();
        $this->assertSame($doc->id, $this->packing->confirm($this->admin->id, $data)->id);
        $this->assertSame($count, StockMovement::count());
        $this->assertNull($doc->journal);
    }

    public function test_partial_packing_and_invalid_component_or_meter_conversion_are_atomic(): void
    {
        $this->packing->confirm($this->admin->id, $this->packingData('10'));
        $this->packing->confirm($this->admin->id, $this->packingData('20'));
        $this->assertSame('70.000000', app(StockLedger::class)->onHand($this->suitPosition));
        $d = $this->packingData();
        $d['materials'][0]['quantity'] = '11';
        $before = Document::count();
        $this->rejected(fn () => $this->packing->confirm($this->admin->id, $d), 'reconcile exactly');
        $this->assertSame($before, Document::count());
        $d = $this->packingData();
        $d['materials'][0]['stock_position_id'] = $this->bagPosition;
        $this->rejected(fn () => $this->packing->confirm($this->admin->id, $d), 'must match');
        $d = $this->packingData();
        $d['outputs'][0]['quantity'] = '0.5';
        $this->rejected(fn () => $this->packing->confirm($this->admin->id, $d), 'base-unit precision');
    }

    public function test_retained_rejected_output_is_valued_but_cannot_be_picked(): void
    {
        $d = $this->packingData();
        $d['outputs'][0]['quality_status'] = 'rejected';
        $doc = $this->packing->confirm($this->admin->id, $d);
        $report = app(ActualCosting::class)->report($doc);
        $this->assertSame('620.0000', $report['total']);
        $this->assertSame('10.000000', $report['rejected']);
        $this->assertSame('0', $report['good']);
        $this->rejected(fn () => app(StockLedger::class)->position($report['rows'][0]['output']->stock_position_id, $this->date, true), 'Only released');
    }

    public function test_full_recovery_unpack_is_new_stock_conversion_and_requires_exact_contents(): void
    {
        $doc = $this->packing->confirm($this->admin->id, $this->packingData());
        $out = PackingOutputLine::where('document_id', $doc->id)->first();
        $d = ['document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'product_pack_spec_id' => $this->packSpec->id, 'operation' => 'unpack', 'remarks' => 'All contents physically recovered and inspected.', 'materials' => [['stock_position_id' => $out->stock_position_id, 'quantity' => '5']], 'outputs' => [['item_id' => $this->variant->item_id, 'warehouse_location_id' => $this->destination->id, 'lot_number' => 'RECOVER-SUITS', 'quantity' => '5', 'quality_status' => 'released', 'cost_allocation_fraction' => '0.9677419355'], ['item_id' => $this->bag->id, 'warehouse_location_id' => $this->destination->id, 'lot_number' => 'RECOVER-BAGS', 'quantity' => '5', 'quality_status' => 'released', 'cost_allocation_fraction' => '0.0322580645']]];
        $u = $this->packing->confirm($this->admin->id, $d);
        $this->assertSame('5.000000', app(StockLedger::class)->onHand($out->stock_position_id));
        $this->assertSame('310.0000', app(ActualCosting::class)->report($u)['total']);
        $d['idempotency_key'] = (string) Str::uuid();
        $d['outputs'][1]['quantity'] = '4';
        $d['outputs'][0]['lot_number'] = 'SECOND-RECOVERY-SUITS';
        $d['outputs'][1]['lot_number'] = 'SECOND-RECOVERY-BAGS';
        $this->rejected(fn () => $this->packing->confirm($this->admin->id, $d), 'reconcile exactly');
    }

    public function test_historical_cost_does_not_change_after_consumption_or_new_specification(): void
    {
        $before = app(ActualCosting::class)->report($this->finishedBatch);
        $this->assertSame('6000.0000', $before['total']);
        $doc = $this->packing->confirm($this->admin->id, $this->packingData());
        $after = app(ActualCosting::class)->report($this->finishedBatch);
        $this->assertSame($before['total'], $after['total']);
        app(PackSpecificationService::class)->publish($this->admin->id, ['packed_item_id' => $this->packItem->id, 'description' => 'New packaging specification version', 'lines' => $this->packSpec->lines->map(fn ($l) => ['component_item_id' => $l->component_item_id, 'component_kind' => $l->component_kind, 'quantity_per_pack' => $l->quantity_per_pack])->all()]);
        $this->assertSame('620.0000', app(ActualCosting::class)->report($doc)['total']);
        try {
            $this->packSpec->update(['description' => 'Rewrite history']);
            $this->fail('Expected immutable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_rounding_conserves_each_source_and_each_output_without_using_float_costs(): void
    {
        $saved = $this->item;
        $this->item = $this->bag;
        $bag = $this->position($this->opening('3', '0.00003333'));
        $this->item = $saved;
        $d = $this->packingData('3');
        $d['materials'][1]['stock_position_id'] = $bag;
        $d['outputs'][0]['quantity'] = '1';
        $d['outputs'][0]['packages'][0]['quantity'] = '1';
        $second = $d['outputs'][0];
        $second['quantity'] = '2';
        $second['lot_number'] = 'ROUNDING-PACK-TWO';
        $second['packages'][0]['package_code'] = 'ROUNDING-BOX-TWO';
        $second['packages'][0]['quantity'] = '2';
        $d['outputs'][] = $second;
        $doc = $this->packing->confirm($this->admin->id, $d);
        $cost = app(ActualCosting::class)->report($doc);
        $this->assertSame('180.0001', $cost['total']);
        foreach ($cost['rows'] as $r) {
            $links = InventoryCostLink::where('destination_stock_cost_layer_id', $r['layer']->id)->sum('allocated_cost_pkr');
            $this->assertSame($r['cost'], bcadd((string) $links, '0', 4));
        }
        $this->assertSame('60.0000', $cost['rows'][0]['cost']);
        $this->assertSame('120.0001', $cost['rows'][1]['cost']);
    }

    public function test_package_quantities_and_saved_provenance_are_revalidated_before_posting(): void
    {
        $d = $this->packingData();
        $d['outputs'][0]['packages'][0]['quantity'] = '9';
        $this->rejected(fn () => $this->packing->confirm($this->admin->id, $d), 'cover each output');
        $draft = $this->packing->save($this->admin->id, $this->packingData());
        $content = PackageContent::firstOrFail();
        $content->update(['quantity' => '9']);
        $this->rejected(fn () => $this->packing->post($this->admin->id, $draft->id, (string) Str::uuid(), $draft->lock_version), 'exactly its specification');
        $content->update(['quantity' => '10']);
        $posted = $this->packing->post($this->admin->id, $draft->id, (string) Str::uuid(), $draft->lock_version);
        try {
            Package::first()->update(['package_code' => 'REWRITE']);
            $this->fail('Expected immutable package.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertSame('posted', $posted->status);
    }

    public function test_pack_specs_cannot_convert_fabric_to_suits_or_redefine_existing_assortments(): void
    {
        $d = ['packed_item_id' => $this->packItem->id, 'description' => 'Invalid assortment', 'lines' => [['component_item_id' => $this->item->id, 'component_kind' => 'saleable_content', 'quantity_per_pack' => '6']]];
        $this->rejected(fn () => app(PackSpecificationService::class)->publish($this->admin->id, $d), 'SUIT variant');
        $d['lines'][0]['component_item_id'] = $this->variant->item_id;
        $d['lines'][0]['quantity_per_pack'] = '2';
        $this->rejected(fn () => app(PackSpecificationService::class)->publish($this->admin->id, $d), 'new packed SKU');
        $this->rejected(fn () => app(CatalogService::class)->saveConversion($this->packItem, ['unit_id' => Unit::where('code', 'SUIT')->value('id'), 'factor_to_base' => '1']), 'packing specification');
    }

    public function test_actual_posted_labor_overhead_and_transport_sources_are_included_once(): void
    {
        $docs = app(PurchaseDocuments::class);
        $type = DocumentType::firstOrCreate(['code' => 'test_cost_source'], ['name' => 'Recognized actual manufacturing cost', 'module_code' => 'accounting', 'prefix' => 'CST']);
        $source = Document::create(['document_type_id' => $type->id, 'document_date' => $this->date, 'financial_year_id' => $this->finishedBatch->financial_year_id, 'accounting_period_id' => $this->finishedBatch->accounting_period_id, 'status' => 'draft', 'created_by' => $this->admin->id, 'idempotency_key' => (string) Str::uuid()]);
        $map = [$docs->account('production_labor') => '300', $docs->account('production_overhead') => '100', $docs->account('production_conversion') => '100', $docs->account('opening_equity') => '-500'];
        DB::transaction(function () use ($source, $map, $docs) {
            app(SourceJournal::class)->post($source, $map);
            $docs->finish($source, $this->admin->id, (string) Str::uuid(), hash('sha256', 'Actual evidenced source', true));
        });
        $batch = $this->batch('20');
        $p = $this->position($this->opening('120', '10'));
        $d = $this->reportData($batch, $p, '120', '20', true);
        $d['costs'] = [];
        foreach (['direct_labor' => 'production_labor', 'overhead' => 'production_overhead', 'other_conversion' => 'production_conversion'] as $category => $purpose) {
            $line = JournalLine::where('journal_entry_id', $source->id)->where('ledger_account_id', $docs->account($purpose))->first();
            $d['costs'][] = ['source_journal_line_id' => $line->id, 'output_index' => 0, 'cost_category' => $category, 'amount_pkr' => $line->debit];
        }
        $this->production->confirm($this->admin->id, $d);
        $report = app(ActualCosting::class)->report($batch);
        $this->assertSame('1700.0000', $report['total']);
        $this->assertSame('85.0000', $report['rows'][0]['per_suit']);
        $this->assertCount(4, $report['all']);
        $again = app(ActualCosting::class)->report($batch);
        $this->assertSame($report['all'], $again['all']);
    }

    public function test_current_value_and_original_cost_survive_a_damage_reversal(): void
    {
        $doc = $this->packing->confirm($this->admin->id, $this->packingData());
        $out = PackingOutputLine::where('document_id', $doc->id)->first();
        $layer = StockCostLayer::where('receipt_stock_movement_id', $out->stock_movement_id)->first();
        $damage = $this->postDocument($this->adjustment($out->stock_position_id, '-2'));
        $this->assertSame('496.0000', app(ActualCosting::class)->total(app(ActualCosting::class)->layer($layer->id)));
        $this->inventory->reverse($this->admin->id, $damage->id, $this->date, 'Actual disposal was cancelled; quantities verified.', (string) Str::uuid());
        $this->assertSame('620.0000', app(ActualCosting::class)->total(app(ActualCosting::class)->layer($layer->id)));
        $this->assertSame('620.0000', app(ActualCosting::class)->report($doc)['total']);
    }

    public function test_a_confirmed_packing_key_cannot_be_reused_for_changed_details(): void
    {
        $d = $this->packingData();
        $doc = $this->packing->confirm($this->admin->id, $d);
        $d['remarks'] = 'Changed after confirmation.';
        $this->rejected(fn () => $this->packing->confirm($this->admin->id, $d), 'different packing details');
        $this->assertSame(1, PackingOrder::count());
    }

    public function test_pack_item_form_allows_only_explicit_pack_measurement_and_preserves_variants(): void
    {
        $this->post(route('catalog.store', ['master' => 'items']), ['sku' => 'PACK-FROM-FORM', 'name' => 'Separate packed product', 'category_id' => $this->packItem->category_id, 'item_type' => 'finished', 'base_unit_id' => Unit::where('code', 'PACK')->value('id'), 'is_stocked' => 1, 'track_rolls' => 0, 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $created = Item::where('sku', 'PACK-FROM-FORM')->firstOrFail();
        $this->assertSame('PACK', $created->baseUnit->code);
        $this->assertNull($created->variant);
        $line = ProductPackSpecLine::factory()->create();
        $this->assertSame('draft', $line->spec->state);
        $this->assertSame('SUIT', $line->item->baseUnit->code);
        $identity = $this->variant->only(['item_id', 'design_id', 'color_id', 'configuration_code', 'piece_count', 'is_active', 'description']);
        $identity['piece_count'] = 99;
        $this->rejected(fn () => app(CatalogService::class)->save('variants', $identity, $this->variant->id), 'Published BOMs');
    }

    public function test_meter_measured_packing_supply_is_reconciled_without_converting_it_to_suits(): void
    {
        $paper = Item::factory()->create(['sku' => 'PACK-PAPER-M', 'item_type' => 'packaging', 'base_unit_id' => $this->item->base_unit_id, 'is_stocked' => true, 'track_rolls' => false]);
        $saved = $this->item;
        $this->item = $paper;
        $position = $this->position($this->opening('5', '3'));
        $this->item = $saved;
        $lines = $this->packSpec->lines->map(fn ($l) => ['component_item_id' => $l->component_item_id, 'component_kind' => $l->component_kind, 'quantity_per_pack' => $l->quantity_per_pack])->all();
        $lines[] = ['component_item_id' => $paper->id, 'component_kind' => 'packaging', 'quantity_per_pack' => '0.5'];
        $spec = app(PackSpecificationService::class)->publish($this->admin->id, ['packed_item_id' => $this->packItem->id, 'description' => 'Actual paper by meter', 'lines' => $lines]);
        $d = $this->packingData();
        $d['product_pack_spec_id'] = $spec->id;
        $d['materials'][0]['product_pack_spec_line_id'] = $spec->lines->firstWhere('component_item_id', $this->variant->item_id)->id;
        $d['materials'][1]['product_pack_spec_line_id'] = $spec->lines->firstWhere('component_item_id', $this->bag->id)->id;
        $d['materials'][] = ['product_pack_spec_line_id' => $spec->lines->firstWhere('component_item_id', $paper->id)->id, 'stock_position_id' => $position, 'quantity' => '5'];
        $doc = $this->packing->confirm($this->admin->id, $d);
        $report = app(ActualCosting::class)->report($doc);
        $this->assertSame('635.0000', $report['total']);
        $this->assertSame('63.5000', $report['rows'][0]['per_suit']);
        $this->assertSame('0.000000', app(StockLedger::class)->onHand($position));
    }
}
