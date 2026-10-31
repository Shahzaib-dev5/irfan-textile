<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PartyRoleType;
use App\Models\PaymentTerm;
use App\Models\ProcessingJob;
use App\Models\ProcessingReceipt;
use App\Models\ProcessingService as Capability;
use App\Models\Unit;
use App\Models\VendorProfile;
use App\Models\VendorService;
use App\Models\VendorServiceRate;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryInquiry;
use App\Modules\Processing\Services\ProcessingRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProcessingFixtures;
use Tests\TestCase;

class ProcessingRoutesTest extends TestCase
{
    use ProcessingFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processingFixtures();
    }

    private function stage(int $service, int $in, int $out): array
    {
        return ['processing_service_id' => $service, 'input_item_id' => $in, 'output_item_id' => $out, 'planned_yield_ratio' => '1', 'permitted_normal_loss_fraction' => '0.05'];
    }

    public function test_configurable_multi_vendor_stages_use_previous_accepted_output(): void
    {
        $finished = Item::factory()->create(['base_unit_id' => $this->item->base_unit_id, 'category_id' => $this->item->category_id, 'item_type' => 'processed_fabric', 'is_active' => true, 'is_stocked' => true, 'track_rolls' => false]);
        $party = Party::create(['code' => 'PRESSER', 'legal_name' => 'Pressing house', 'party_kind' => 'organization', 'is_active' => true]);
        $v = PartyRole::create(['party_id' => $party->id, 'party_role_type_id' => PartyRoleType::where('code', 'vendor')->value('id'), 'is_active' => true]);
        VendorProfile::create(['party_role_id' => $v->id, 'payment_term_id' => PaymentTerm::first()->id]);
        $cap = VendorService::create(['vendor_party_role_id' => $v->id, 'processing_service_id' => Capability::where('code', 'pressing')->value('id'), 'is_active' => true]);
        VendorServiceRate::create(['vendor_service_id' => $cap->id, 'billing_unit_id' => $this->item->base_unit_id, 'unit_rate' => '1', 'rate_reason' => 'Signed pressing contract', 'currency_code' => 'PKR', 'effective_from' => $this->date, 'approved_by' => $this->admin->id]);
        $loc = WarehouseLocation::factory()->create(['location_type' => 'contractor', 'contractor_vendor_party_role_id' => $v->id]);
        $r = app(ProcessingRoutes::class)->create($this->admin->id, ['route_code' => 'DYEPRESS', 'name' => 'Dye then press', 'steps' => [$this->stage($this->capability->processing_service_id, $this->item->id, $finished->id), $this->stage($cap->processing_service_id, $finished->id, $finished->id)]]);
        app(ProcessingRoutes::class)->publish($this->admin->id, $r->id);
        $j = $this->processingPost($this->processingDraft('job', ['processing_route_version_id' => $r->id, 'planned_input_quantity' => '100', 'lines' => [['vendor_service_id' => $this->capability->id], ['vendor_service_id' => $cap->id]]]));
        $d = $this->dispatch($j, $this->position($this->opening()));
        $receipt = $this->processingReceipt($d);
        $output = ProcessingReceipt::find($receipt->id)->lines->first()->output_stock_position_id;
        $second = $this->processingPost($this->processingDraft('dispatch', ['processing_job_document_id' => $j->id, 'vendor_party_role_id' => $v->id, 'lines' => [['processing_job_step_id' => ProcessingJob::find($j->id)->lines->last()->id, 'source_stock_position_id' => $output, 'contractor_location_id' => $loc->id, 'quantity' => '100']]]));
        $this->vendor = $v;
        $rejected = $this->processingReceipt($second, '20', '20', true, ['disposition' => 'rejected']);
        $rejectedPosition = ProcessingReceipt::find($rejected->id)->lines->first()->output_stock_position_id;
        $again = $this->processingPost($this->processingDraft('dispatch', ['processing_job_document_id' => $j->id, 'vendor_party_role_id' => $v->id, 'lines' => [['processing_job_step_id' => ProcessingJob::find($j->id)->lines->last()->id, 'source_stock_position_id' => $rejectedPosition, 'contractor_location_id' => $loc->id, 'quantity' => '20']]]));
        $this->processingReceipt($second, '80', '80');
        $this->processingReceipt($again, '20', '20');
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_combined_and_separate_services_are_configuration_not_a_fixed_sequence(): void
    {
        $s = app(ProcessingRoutes::class);
        $dan = Capability::where('code', 'dan')->first();
        $combined = Capability::where('code', 'combined-dan-dyeing')->first();
        foreach ([[$this->stage($combined->id, $this->item->id, $this->item->id)], [$this->stage($dan->id, $this->item->id, $this->item->id), $this->stage($this->capability->processing_service_id, $this->item->id, $this->item->id)]] as $steps) {
            $r = $s->create($this->admin->id, ['route_code' => 'DANROUTE', 'name' => 'Dan options', 'steps' => $steps]);
            $s->publish($this->admin->id, $r->id);
            $this->assertSame('published', $r->fresh()->state);
        }
        $this->assertDatabaseHas('processing_route_versions', ['route_code' => 'DANROUTE', 'version_number' => 2]);
    }

    public function test_published_route_cannot_be_edited(): void
    {
        $this->expectException(\LogicException::class);
        $this->route->steps->first()->update(['planned_yield_ratio' => '0.5']);
    }

    public function test_incompatible_stage_units_are_rejected(): void
    {
        $piece = Item::factory()->create(['base_unit_id' => Unit::where('code', 'PCS')->value('id'), 'is_stocked' => true, 'is_active' => true]);
        $s = app(ProcessingRoutes::class);
        $r = $s->create($this->admin->id, ['route_code' => 'BADUNIT', 'name' => 'Invalid cross dimension', 'steps' => [$this->stage($this->capability->processing_service_id, $this->item->id, $piece->id)]]);
        $this->expectException(ValidationException::class);
        $s->publish($this->admin->id, $r->id);
    }

    public function test_route_discontinuity_is_rejected(): void
    {
        $other = Item::factory()->create(['base_unit_id' => $this->item->base_unit_id, 'is_stocked' => true, 'is_active' => true]);
        $s = app(ProcessingRoutes::class);
        $r = $s->create($this->admin->id, ['route_code' => 'BADCHAIN', 'name' => 'Broken route', 'steps' => [$this->stage($this->capability->processing_service_id, $this->item->id, $this->item->id), $this->stage($this->capability->processing_service_id, $other->id, $other->id)]]);
        $this->expectException(ValidationException::class);
        $s->publish($this->admin->id, $r->id);
    }

    public function test_new_service_type_can_be_configured_without_code_changes(): void
    {
        $s = app(ProcessingRoutes::class)->service($this->admin->id, ['code' => 'washing', 'name' => 'Washing', 'billing_unit_id' => $this->item->base_unit_id]);
        $this->assertDatabaseHas('processing_services', ['id' => $s->id, 'code' => 'washing']);
    }

    public function test_route_yield_outside_decimal_range_returns_validation_error(): void
    {
        $stage = $this->stage($this->capability->processing_service_id, $this->item->id, $this->item->id);
        $stage['planned_yield_ratio'] = '10000000000';
        $this->expectException(ValidationException::class);
        app(ProcessingRoutes::class)->create($this->admin->id, ['route_code' => 'OVERFLOW', 'name' => 'Invalid decimal range', 'steps' => [$stage]]);
    }
}
