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
use App\Models\StockMovement;
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
            'fabric_type' => 'Lawn', 'color' => 'Blue', 'component_type' => 'Dupatta', 'design_reference' => 'D-01'];
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

        return ['destination' => 'vendor', 'next_vendor_party_role_id' => $role->id, 'next_vendor_service_id' => $cap->id, 'next_contractor_location_id' => $location->id];
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

    public function test_fabric_out_creates_vendor_custody_warehouse_when_location_is_blank(): void
    {
        $before = Warehouse::count();
        $data = array_replace($this->outData(), ['contractor_location_id' => null]);
        $flow = app(FabricFlowService::class)->execute($this->admin->id, $data);
        $dispatch = ProcessingDispatch::findOrFail($flow->document_id);
        $location = $dispatch->lines->first()->contractor->location;

        $this->assertSame('contractor', $location->location_type);
        $this->assertSame($this->vendor->id, $location->contractor_vendor_party_role_id);
        $this->assertContains(Warehouse::count(), [$before, $before + 1]);
        $this->assertTrue($location->warehouse->is_active);
        $this->assertSame($location->id, ProcessingDispatch::findOrFail($flow->document_id)->lines->first()->contractor->location->id);
        $this->assertSame($flow->id, app(FabricFlowService::class)->execute($this->admin->id, $data)->id);
        $this->assertContains(Warehouse::count(), [$before, $before + 1]);
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

    public function test_invalid_next_contract_rolls_back_receipt_costs_and_derived_items(): void
    {
        $s = app(FabricFlowService::class);
        $out = $s->execute($this->admin->id, $this->outData());
        $next = $this->nextVendor();
        VendorServiceRate::where('vendor_service_id', $next['next_vendor_service_id'])->update(['effective_from' => today()->addDay()]);
        $counts = [Document::count(), StockMovement::count(), Item::count(), ProcessingFabricFlow::count()];
        try {
            $s->execute($this->admin->id, array_replace($this->inData($out), $next));
            $this->fail('Missing contract accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('published', $e->getMessage());
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
        $out = $s->execute($this->admin->id, array_replace($this->outData(), ['vendor_service_id' => $cap->id]));
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
        foreach (['processing.fabric.out', 'processing.fabric.in', 'processing.overview'] as $route) {
            $this->get(route($route))->assertOk();
        }
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
