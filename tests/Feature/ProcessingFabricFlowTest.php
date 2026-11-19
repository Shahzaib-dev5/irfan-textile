<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AccountingPeriod;
use App\Models\Document;
use App\Models\InventoryValueEntry;
use App\Models\Item;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingFabricFlow;
use App\Models\ProcessingReceipt;
use App\Models\ProcessingService;
use App\Models\PurchaseInvoice;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\VendorProfile;
use App\Models\VendorService;
use App\Models\VendorServiceRate;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Processing\Services\FabricFlowService;
use Database\Seeders\SalesSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProcessingFixtures;
use Tests\TestCase;

class ProcessingFabricFlowTest extends TestCase
{
    use ProcessingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processingFixtures();
    }

    private function outData(): array
    {
        return ['kind' => 'out', 'intent' => 'confirm', 'request_key' => (string) Str::uuid(), 'document_date' => $this->date,
            'vendor_party_role_id' => $this->vendor->id, 'vendor_service_id' => $this->capability->id,
            'source_stock_position_id' => $this->position($this->opening()), 'quantity' => '100', 'contractor_location_id' => $this->custody->id,
            'agreed_unit_rate_pkr' => '2', 'fabric_type' => 'Lawn', 'color' => 'Blue', 'component_type' => 'Dupatta', 'design_reference' => 'D-01'];
    }

    private function browserFixture(string $name, $response): void
    {
        if (getenv('FABRIC_BROWSER_FIXTURES')) {
            $directory = storage_path('framework/testing/fabric-browser');
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }
    }

    private function inData(ProcessingFabricFlow $out): array
    {
        return ['kind' => 'in', 'intent' => 'confirm', 'request_key' => (string) Str::uuid(), 'document_date' => $this->date,
            'processing_dispatch_line_id' => ProcessingDispatch::findOrFail($out->document_id)->lines->first()->id,
            'destination' => 'warehouse', 'disposition' => 'accepted', 'source_quantity' => '60', 'output_quantity' => '60',
            'receiving_location_id' => $this->source->id, 'quality_status' => 'released', 'laat_reference' => 'LAAT-34',
            'component_type' => 'Dupatta', 'component_count' => '34'];
    }

    private function nextVendor(): array
    {
        $party = Party::create(['code' => 'PRESS', 'legal_name' => 'Press house', 'party_kind' => 'organization', 'is_active' => true]);
        $role = PartyRole::create(['party_id' => $party->id, 'party_role_type_id' => $this->vendor->party_role_type_id, 'is_active' => true]);
        VendorProfile::create(['party_role_id' => $role->id, 'payment_term_id' => $this->vendor->vendorProfile->payment_term_id]);
        $cap = VendorService::create(['vendor_party_role_id' => $role->id, 'processing_service_id' => ProcessingService::where('code', 'calendaring')->value('id'), 'is_active' => true]);
        VendorServiceRate::create(['vendor_service_id' => $cap->id, 'billing_unit_id' => $this->item->base_unit_id, 'unit_rate' => '3', 'currency_code' => 'PKR', 'effective_from' => today()->startOfYear()->format('Y-m-d'), 'approved_by' => $this->admin->id, 'rate_reason' => 'Signed press contract']);
        $location = WarehouseLocation::factory()->create(['location_type' => 'contractor', 'contractor_vendor_party_role_id' => $role->id]);

        return ['destination' => 'vendor', 'next_agreed_unit_rate_pkr' => '3', 'next_vendor_party_role_id' => $role->id, 'next_vendor_service_id' => $cap->id, 'next_contractor_location_id' => $location->id];
    }

    public function test_simple_out_derives_output_and_retry_does_not_duplicate_stock(): void
    {
        $s = app(FabricFlowService::class);
        $d = $this->outData();
        $flow = $s->execute($this->admin->id, $d);
        $dispatch = ProcessingDispatch::findOrFail($flow->document_id);
        $step = $dispatch->lines->first()->step;
        $this->assertSame('processed_fabric', $step->outputItem->item_type);
        $this->assertNotSame($step->input_item_id, $step->output_item_id);
        $this->assertSame('grey_fabric', $this->item->fresh()->item_type);
        $count = StockMovement::count();
        $this->assertSame($flow->id, $s->execute($this->admin->id, $d)->id);
        $this->assertSame($count, StockMovement::count());
        $this->get(route('processing.show', $flow->document_id))->assertOk()->assertSee('Fabric / laat details');
        $this->expectException(ValidationException::class);
        $s->execute($this->admin->id, array_replace($d, ['quantity' => '99']));
    }

    public function test_dispatch_rate_needs_no_published_contract_and_is_preserved_for_receipt(): void
    {
        VendorServiceRate::where('vendor_service_id', $this->capability->id)->delete();
        $data = array_replace($this->outData(), ['agreed_unit_rate_pkr' => '7.25']);
        $service = app(FabricFlowService::class);
        $out = $service->execute($this->admin->id, $data);
        $step = ProcessingDispatch::findOrFail($out->document_id)->lines->first()->step;
        $this->assertNull($step->vendor_service_rate_id);
        $this->assertSame('7.25000000', $step->agreed_unit_rate_pkr);
        $this->assertSame(0, VendorServiceRate::where('vendor_service_id', $this->capability->id)->count());
        $this->assertDatabaseHas('audit_events', ['document_id' => $step->document_id, 'action' => 'dispatch_rate_agreed']);
        $receipt = $service->execute($this->admin->id, $this->inData($out));
        $this->assertSame('435.0000', ProcessingReceipt::findOrFail($receipt->document_id)->lines->first()->service_accrual_pkr);
        $count = StockMovement::count();
        $this->assertSame($out->id, $service->execute($this->admin->id, $data)->id);
        $this->assertSame($count, StockMovement::count());
        $this->expectException(ValidationException::class);
        $service->execute($this->admin->id, array_replace($data, ['agreed_unit_rate_pkr' => '8']));
    }

    public function test_missing_dispatch_rate_is_pending_and_must_be_finalized_on_receipt(): void
    {
        $service = app(FabricFlowService::class);
        $data = $this->outData();
        unset($data['agreed_unit_rate_pkr']);
        $out = $service->execute($this->admin->id, $data);
        $this->assertTrue(ProcessingDispatch::findOrFail($out->document_id)->lines->first()->step->rate_pending);
        $before = [Document::count(), StockMovement::count(), Item::count()];
        try {
            $service->execute($this->admin->id, $this->inData($out));
            $this->fail('Pending receipt price accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Rate pending', $e->getMessage());
        }
        $this->assertSame($before, [Document::count(), StockMovement::count(), Item::count()]);
        $receipt = $service->execute($this->admin->id, array_replace($this->inData($out), ['actual_unit_rate_pkr' => '0', 'actual_billing_unit_id' => $this->item->base_unit_id]));
        $this->assertSame('0.0000', ProcessingReceipt::findOrFail($receipt->document_id)->lines->first()->service_accrual_pkr);
    }

    public function test_meter_receipts_convert_to_pieces_and_freeze_each_actual_rate_for_billing(): void
    {
        $service = app(FabricFlowService::class);
        $out = $service->execute($this->admin->id, array_replace($this->outData(), ['agreed_unit_rate_pkr' => null]));
        $pcs = Unit::where('code', 'PCS')->value('id');
        $input = array_replace($this->inData($out), ['return_unit_code' => 'PCS', 'output_quantity' => '12', 'output_source_equivalent_quantity' => '60', 'actual_unit_rate_pkr' => '200', 'actual_billing_unit_id' => $pcs, 'remarks' => 'Measured sixty meters cut into twelve pieces.']);
        $received = $service->execute($this->admin->id, $input);
        $line = ProcessingReceipt::findOrFail($received->document_id)->lines->first();
        $this->assertSame('PCS', $line->output->lot->item->baseUnit->code);
        $this->assertSame('60.000000', $line->output_source_equivalent_quantity);
        $this->assertSame('12.000000', app(StockLedger::class)->onHand($line->output_stock_position_id));
        $this->assertSame('40.000000', $this->processing->outstanding($line->dispatchLine));
        $this->assertSame('2400.0000', $line->service_accrual_pkr);
        $this->assertSame('3000.0000', bcadd((string) InventoryValueEntry::whereIn('stock_cost_layer_id', StockCostLayer::where('stock_position_id', $line->output_stock_position_id)->select('id'))->sum('amount_pkr'), '0', 4));
        $second = $service->execute($this->admin->id, array_replace($input, ['request_key' => (string) Str::uuid(), 'source_quantity' => '40', 'output_quantity' => '8', 'output_source_equivalent_quantity' => '40', 'actual_unit_rate_pkr' => '250']));
        $this->assertSame('2000.0000', ProcessingReceipt::findOrFail($second->document_id)->lines->first()->service_accrual_pkr);
        $before = StockMovement::count();
        $bill = $this->contractorBill(Document::findOrFail($received->document_id), '12');
        $invoice = PurchaseInvoice::findOrFail($bill->id);
        $this->assertSame('2400.0000', $invoice->gross_amount);
        $this->assertSame($pcs, $invoice->lines->first()->unit_id);
        $this->assertSame('200.00000000', $invoice->lines->first()->unit_price);
        $this->assertSame($before, StockMovement::count());
        $this->assertSame($received->id, $service->execute($this->admin->id, $input)->id);
        $this->get(route('processing.show', $received->document_id))->assertOk()->assertSee('PCS');
    }

    public function test_invalid_piece_measurements_roll_back_everything(): void
    {
        $service = app(FabricFlowService::class);
        $out = $service->execute($this->admin->id, $this->outData());
        $input = array_replace($this->inData($out), ['return_unit_code' => 'PCS', 'output_quantity' => '12', 'output_source_equivalent_quantity' => '60', 'remarks' => 'Actual cutting measurement evidence.', 'actual_unit_rate_pkr' => '200', 'actual_billing_unit_id' => Unit::where('code', 'PCS')->value('id')]);
        $before = [Document::count(), StockMovement::count(), Item::count()];
        foreach ([['output_source_equivalent_quantity' => '59'], ['output_quantity' => '12.5'], ['remarks' => null], ['source_quantity' => '101', 'output_source_equivalent_quantity' => '101']] as $bad) {
            try {
                $service->execute($this->admin->id, array_replace($input, $bad));
                $this->fail('Invalid conversion accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
            $this->assertSame($before, [Document::count(), StockMovement::count(), Item::count()]);
        }
    }

    public function test_cutting_before_dispatch_is_atomic_and_preserves_cost_and_history(): void
    {
        $service = app(FabricFlowService::class);
        $data = $this->outData();
        $cap = VendorService::create(['vendor_party_role_id' => $this->vendor->id, 'processing_service_id' => ProcessingService::where('code', 'calendaring')->value('id'), 'is_active' => true]);
        $data = array_replace($data, ['vendor_service_id' => $cap->id, 'agreed_unit_rate_pkr' => null, 'cut_before_dispatch' => true, 'cut_piece_quantity' => '20', 'cut_retained_meters' => '90', 'cut_normal_loss' => '0', 'cut_abnormal_loss' => '10', 'cut_evidence' => 'Measured 100 meters; twenty pieces retain 90 meters.']);
        $flow = $service->execute($this->admin->id, $data);
        $dispatch = ProcessingDispatch::findOrFail($flow->document_id)->lines->first();
        $cut = DB::table('fabric_cuttings')->first();
        $this->assertSame('0.000000', app(StockLedger::class)->onHand($data['source_stock_position_id']));
        $this->assertSame('20.000000', app(StockLedger::class)->onHand($dispatch->contractor_stock_position_id));
        $this->assertSame('PCS', $dispatch->step->inputItem->baseUnit->code);
        $this->assertSame('1000.0000', $cut->input_cost_pkr);
        $this->assertSame('100.0000', $cut->abnormal_cost_pkr);
        $this->assertSame('900.0000', bcadd((string) InventoryValueEntry::whereIn('stock_cost_layer_id', StockCostLayer::where('stock_position_id', $dispatch->contractor_stock_position_id)->select('id'))->sum('amount_pkr'), '0', 4));
        $before = StockMovement::count();
        $this->assertSame($flow->id, $service->execute($this->admin->id, $data)->id);
        $this->assertSame($before, StockMovement::count());
        $this->get(route('processing.cutting.show', $cut->document_id))->assertOk()->assertSee('Cut pieces');
        $receipt = $service->execute($this->admin->id, array_replace($this->inData($flow), ['source_quantity' => '20', 'output_quantity' => '20', 'actual_unit_rate_pkr' => '200', 'actual_billing_unit_id' => Unit::where('code', 'PCS')->value('id')]));
        $this->assertSame('4000.0000', ProcessingReceipt::findOrFail($receipt->document_id)->lines->first()->service_accrual_pkr);
    }

    public function test_converted_receipt_can_be_handed_to_next_vendor_with_rate_pending(): void
    {
        $service = app(FabricFlowService::class);
        $out = $service->execute($this->admin->id, $this->outData());
        $input = array_replace($this->inData($out), $this->nextVendor(), ['return_unit_code' => 'PCS', 'output_quantity' => '12', 'output_source_equivalent_quantity' => '60', 'actual_unit_rate_pkr' => '200', 'actual_billing_unit_id' => Unit::where('code', 'PCS')->value('id'), 'remarks' => 'Sixty measured meters returned as twelve pieces.', 'next_agreed_unit_rate_pkr' => null]);
        $flow = $service->execute($this->admin->id, $input);
        $receipt = ProcessingReceipt::findOrFail($flow->document_id)->lines->first();
        $onward = ProcessingDispatch::findOrFail($flow->onward_document_id)->lines->first();
        $this->assertSame('PCS', $onward->step->inputItem->baseUnit->code);
        $this->assertTrue($onward->step->rate_pending);
        $this->assertSame('12.000000', app(StockLedger::class)->onHand($onward->contractor_stock_position_id));
        $this->assertSame('0.000000', app(StockLedger::class)->onHand($receipt->output_stock_position_id));
        $this->assertSame('40.000000', $this->processing->outstanding($receipt->dispatchLine));
    }

    public function test_converted_receipt_reconciles_losses_and_actual_meter_billing(): void
    {
        $service = app(FabricFlowService::class);
        $out = $service->execute($this->admin->id, array_replace($this->outData(), ['normal_loss_percent' => '5']));
        $input = array_replace($this->inData($out), ['source_quantity' => '100', 'return_unit_code' => 'PCS', 'output_quantity' => '20', 'output_source_equivalent_quantity' => '90', 'normal_loss_quantity' => '5', 'abnormal_loss_quantity' => '5', 'actual_unit_rate_pkr' => '3', 'actual_billing_unit_id' => $this->item->base_unit_id, 'accepted_service_quantity' => '90', 'remarks' => 'Measured 90 meters retained; five normal and five abnormal loss.']);
        $flow = $service->execute($this->admin->id, $input);
        $line = ProcessingReceipt::findOrFail($flow->document_id)->lines->first();
        $this->assertSame('270.0000', $line->service_accrual_pkr);
        $this->assertSame('50.0000', $line->abnormal_loss_cost_pkr);
        $this->assertSame('1220.0000', bcadd((string) InventoryValueEntry::whereIn('stock_cost_layer_id', StockCostLayer::where('stock_position_id', $line->output_stock_position_id)->select('id'))->sum('amount_pkr'), '0', 4));
        $progress = $this->processing->jobProgress($line->step->job);
        $this->assertSame('90.000000', $progress[$line->processing_job_step_id]['accepted']);
    }

    public function test_historical_receipt_rate_fallback_keeps_original_posting_hash_valid(): void
    {
        $job = $this->job();
        $dispatch = $this->dispatch($job, $this->position($this->opening()));
        $receipt = $this->processingReceipt($dispatch);
        // Model an immutable receipt created before the additive snapshot columns existed.
        $header = $this->processing->header($receipt);
        $details = $header->toArray();
        foreach ($details['lines'] as &$line) {
            unset($line['billing_unit_id'],$line['unit_rate_pkr']);
        }
        unset($line);
        $legacyHash = hash('sha256', json_encode(['document' => $receipt->only(['document_type_id', 'document_date', 'financial_year_id', 'accounting_period_id', 'external_reference']), 'details' => $details], JSON_THROW_ON_ERROR), true);
        DB::table('processing_receipt_lines')->where('document_id', $receipt->id)->update(['billing_unit_id' => null, 'unit_rate_pkr' => null, 'updated_at' => $header->lines->first()->getRawOriginal('updated_at')]);
        DB::table('documents')->where('id', $receipt->id)->update(['payload_hash' => $legacyHash]);
        $after = $this->processing->header($receipt)->toArray();
        foreach ($after['lines'] as &$row) {
            unset($row['billing_unit_id'],$row['unit_rate_pkr']);
        }
        unset($row);
        $this->assertSame($details, $after);
        $replayed = $this->processing->post($this->admin->id, $receipt->id, $receipt->posting_key, $receipt->lock_version);
        $this->assertSame($receipt->id, $replayed->id);
        $bill = $this->contractorBill($receipt);
        $this->assertSame('200.0000', PurchaseInvoice::findOrFail($bill->id)->gross_amount);
    }

    public function test_failed_dispatch_rolls_back_its_cutting(): void
    {
        $service = app(FabricFlowService::class);
        $data = array_replace($this->outData(), ['cut_before_dispatch' => true, 'cut_piece_quantity' => '20', 'cut_retained_meters' => '100', 'cut_evidence' => 'Measured one hundred meters into twenty pieces.']);
        $before = [Document::count(), StockMovement::count(), Item::count()];
        try {
            // The selected meter-only dyeing service cannot receive cut pieces.
            $service->execute($this->admin->id, $data);
            $this->fail('Incompatible dispatch accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Dyeing', $e->getMessage());
        }
        $this->assertSame($before, [Document::count(), StockMovement::count(), Item::count()]);
        $this->assertDatabaseCount('fabric_cuttings', 0);
        $this->assertSame('100.000000', app(StockLedger::class)->onHand($data['source_stock_position_id']));
    }

    public function test_fabric_out_automatically_tracks_vendor_custody_without_another_warehouse(): void
    {
        $this->custody->update(['is_active' => false]);
        $before = Warehouse::count();
        $data = array_replace($this->outData(), ['contractor_location_id' => null]);
        $flow = app(FabricFlowService::class)->execute($this->admin->id, $data);
        $dispatch = ProcessingDispatch::findOrFail($flow->document_id);
        $location = $dispatch->lines->first()->contractor->location;

        $this->assertSame('contractor', $location->location_type);
        $this->assertSame($this->vendor->id, $location->contractor_vendor_party_role_id);
        $this->assertSame($before, Warehouse::count());
        $this->assertTrue($location->warehouse->is_active);
        $this->assertSame($this->source->warehouse_id, $location->warehouse_id);
        $this->assertSame($location->id, ProcessingDispatch::findOrFail($flow->document_id)->lines->first()->contractor->location->id);
        $this->assertSame($flow->id, app(FabricFlowService::class)->execute($this->admin->id, $data)->id);
        $this->assertSame($before, Warehouse::count());
    }

    public function test_partial_receipt_automatically_uses_output_and_preserves_remaining_custody(): void
    {
        $s = app(FabricFlowService::class);
        $out = $s->execute($this->admin->id, $this->outData());
        $in = $s->execute($this->admin->id, $this->inData($out));
        $line = ProcessingReceipt::findOrFail($in->document_id)->lines->first();
        $this->assertSame($line->step->output_item_id, $line->output->lot->item_id);
        $this->assertSame('40.000000', $this->processing->outstanding($line->dispatchLine));
        $this->assertSame('120.0000', $line->service_accrual_pkr);
        $this->assertSame('34', $in->details['component_count']);
        $this->get(route('processing.fabric.in', ['line' => $line->dispatchLine->id]))->assertOk()->assertSee('Directly to another vendor');
    }

    public function test_direct_handover_has_no_warehouse_movement_and_retains_cost_and_custody(): void
    {
        $s = app(FabricFlowService::class);
        $out = $s->execute($this->admin->id, $this->outData());
        $d = array_replace($this->inData($out), $this->nextVendor());
        $this->browserFixture('in', $this->get(route('processing.fabric.in', ['line' => $d['processing_dispatch_line_id']]))->assertOk());
        $in = $s->execute($this->admin->id, $d);
        $receipt = ProcessingReceipt::findOrFail($in->document_id)->lines->first();
        $next = ProcessingDispatch::findOrFail($in->onward_document_id)->lines->first();
        $ledger = app(StockLedger::class);
        $this->assertSame('40.000000', $this->processing->outstanding($receipt->dispatchLine));
        $this->assertSame('60.000000', $this->processing->outstanding($next));
        $this->assertSame('0.000000', $ledger->onHand($receipt->output_stock_position_id));
        $this->assertSame('60.000000', $ledger->onHand($next->contractor_stock_position_id));
        $this->assertSame($receipt->output_stock_position_id, $next->source_stock_position_id);
        $this->assertSame(0, StockMovement::whereIn('document_id', [$in->document_id, $in->onward_document_id])->whereHas('position.location', fn ($q) => $q->where('location_type', 'internal'))->count());
        $this->assertSame('1120.0000', bcadd((string) InventoryValueEntry::sum('amount_pkr'), '0', 4));
        $this->assertTrue($this->processing->custodyMatches($next));
        $count = StockMovement::count();
        $this->assertSame($in->id, $s->execute($this->admin->id, $d)->id);
        $this->assertSame($count, StockMovement::count());
        // The next vendor can return to a real warehouse and accrue only their own charge.
        $nextData = array_replace($this->inData($out), ['processing_dispatch_line_id' => $next->id, 'laat_reference' => 'PRESS-34']);
        $finished = $s->execute($this->admin->id, $nextData);
        $this->assertSame('180.0000', ProcessingReceipt::findOrFail($finished->document_id)->lines->first()->service_accrual_pkr);
        $this->assertSame('1300.0000', bcadd((string) InventoryValueEntry::sum('amount_pkr'), '0', 4));
    }

    public function test_invalid_next_agreed_rate_rolls_back_receipt_costs_and_derived_items(): void
    {
        $s = app(FabricFlowService::class);
        $out = $s->execute($this->admin->id, $this->outData());
        $next = $this->nextVendor();
        $next['next_agreed_unit_rate_pkr'] = '-1';
        $counts = [Document::count(), StockMovement::count(), Item::count(), ProcessingFabricFlow::count()];
        try {
            $s->execute($this->admin->id, array_replace($this->inData($out), $next));
            $this->fail('Invalid rate accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('next_agreed_unit_rate_pkr', $e->errors());
        }
        $this->assertSame($counts, [Document::count(), StockMovement::count(), Item::count(), ProcessingFabricFlow::count()]);
        $this->assertSame('100.000000', $this->processing->outstanding(ProcessingDispatch::findOrFail($out->document_id)->lines->first()));
        // Context resets even after failure: ordinary receipt cannot use contractor storage.
        $this->expectException(ValidationException::class);
        $s->execute($this->admin->id, array_replace($this->inData($out), ['receiving_location_id' => $this->custody->id]));
    }

    public function test_embroidery_bills_suit_count_independently_of_component_count(): void
    {
        $cap = VendorService::create(['vendor_party_role_id' => $this->vendor->id, 'processing_service_id' => ProcessingService::where('code', 'embroidery-suit')->value('id'), 'is_active' => true]);
        VendorServiceRate::create(['vendor_service_id' => $cap->id, 'billing_unit_id' => $cap->processingService->billing_unit_id, 'unit_rate' => '500', 'currency_code' => 'PKR', 'effective_from' => today()->startOfYear()->format('Y-m-d'), 'approved_by' => $this->admin->id, 'rate_reason' => 'Per suit embroidery agreement']);
        $s = app(FabricFlowService::class);
        $out = $s->execute($this->admin->id, array_replace($this->outData(), ['vendor_service_id' => $cap->id, 'agreed_unit_rate_pkr' => '500']));
        $in = $s->execute($this->admin->id, array_replace($this->inData($out), ['component_count' => '66', 'accepted_service_quantity' => '22']));
        $line = ProcessingReceipt::findOrFail($in->document_id)->lines->first();
        $this->assertSame('11000.0000', $line->service_accrual_pkr);
        $this->assertSame('60.000000', $line->output_quantity);
        $this->assertSame('66', $in->details['component_count']);
        $this->assertSame('SUIT', $line->step->billingUnit->code);
        $bill = $this->contractorBill($in->document, '22');
        $this->assertSame('11000.0000', $bill->journal->total_debit);
    }

    public function test_rejected_return_preserves_original_identity_without_charges(): void
    {
        $s = app(FabricFlowService::class);
        $out = $s->execute($this->admin->id, $this->outData());
        $in = $s->execute($this->admin->id, array_replace($this->inData($out), ['disposition' => 'rejected']));
        $line = ProcessingReceipt::findOrFail($in->document_id)->lines->first();
        $this->assertSame($this->item->id, $line->output->lot->item_id);
        $this->assertSame('0.0000', $line->service_accrual_pkr);
    }

    public function test_saved_entry_has_no_posting_effect_and_screens_render(): void
    {
        $s = app(FabricFlowService::class);
        $d = array_replace($this->outData(), ['intent' => 'save']);
        $count = Document::count();
        $flow = $s->execute($this->admin->id, $d);
        $this->assertSame($count, Document::count());
        foreach (['processing.fabric.out', 'processing.fabric.in'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('processing.overview'))->assertRedirect(route('processing.jobs.index'));
        $this->browserFixture('out', $this->get(route('processing.fabric.out'))->assertOk());
        $this->get(route('processing.fabric.draft', $flow))->assertOk()->assertSee('Saved entry');
        $this->assertSame('posted', $s->execute($this->admin->id, array_replace($d, ['intent' => 'confirm']))->status);
    }

    public function test_unreleased_handover_and_overreceipt_are_rejected_atomically(): void
    {
        $s = app(FabricFlowService::class);
        $out = $s->execute($this->admin->id, $this->outData());
        $base = array_replace($this->inData($out), $this->nextVendor());
        $count = StockMovement::count();
        foreach ([['quality_status' => 'pending'], ['source_quantity' => '101', 'output_quantity' => '101']] as $bad) {
            try {
                $s->execute($this->admin->id, array_replace($base, $bad));
                $this->fail('Invalid handover accepted');
            } catch (ValidationException) {
                $this->assertSame($count, StockMovement::count());
            }
        }
    }

    public function test_wholesale_cannot_access_fabric_forms_or_post_and_closed_period_rolls_back(): void
    {
        $this->seed(SalesSetupSeeder::class);
        $d = $this->outData();
        $user = User::factory()->create();
        $role = AccessRole::where('code', 'wholesale')->firstOrFail();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($user)->get(route('processing.fabric.out'))->assertForbidden();
        $this->post(route('processing.fabric.store'), $d)->assertForbidden();
        $this->actingAs($this->admin);
        AccountingPeriod::query()->update(['status' => 'closed', 'closed_by' => $this->admin->id, 'closed_at' => now()]);
        $before = [Document::count(), Item::count(), ProcessingFabricFlow::count()];
        try {
            app(FabricFlowService::class)->execute($this->admin->id, $d);
            $this->fail('Closed period accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('open', $e->getMessage());
        }
        $this->assertSame($before, [Document::count(), Item::count(), ProcessingFabricFlow::count()]);
    }

    public function test_fabric_http_save_and_confirm_and_pdf_include_recorded_details(): void
    {
        $d = array_replace($this->outData(), ['intent' => 'save', 'normal_loss_percent' => '0']);
        $this->post(route('processing.fabric.store'), $d)->assertRedirect();
        $flow = ProcessingFabricFlow::firstOrFail();
        $this->post(route('processing.fabric.store'), array_replace($d, ['intent' => 'confirm']))->assertRedirect();
        $flow->refresh();
        $this->get(route('processing.print', $flow->document_id))->assertOk()->assertSee('Lawn')->assertSee('Blue');
        $this->get(route('processing.pdf', $flow->document_id))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_partial_tracked_roll_and_wrong_vendor_service_are_rejected(): void
    {
        $this->item->update(['track_rolls' => true]);
        $position = $this->position($this->opening('100', '10', true, null, ['roll_number' => 'FULL-ROLL']));
        $base = ['kind' => 'out', 'intent' => 'confirm', 'request_key' => (string) Str::uuid(), 'document_date' => $this->date, 'vendor_party_role_id' => $this->vendor->id, 'vendor_service_id' => $this->capability->id, 'source_stock_position_id' => $position, 'quantity' => '50', 'contractor_location_id' => $this->custody->id, 'fabric_type' => 'Lawn', 'color' => 'Blue'];
        $before = StockMovement::count();
        foreach ([$base, array_replace($base, ['quantity' => '100', 'vendor_service_id' => $this->nextVendor()['next_vendor_service_id']])] as $bad) {
            try {
                app(FabricFlowService::class)->execute($this->admin->id, $bad);
                $this->fail('Invalid source accepted');
            } catch (ValidationException) {
                $this->assertSame($before, StockMovement::count());
            }
        }
    }
}
