<?php

namespace Tests\Support;

use App\Models\Document;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PartyRoleType;
use App\Models\PaymentTerm;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingJob;
use App\Models\ProcessingReceipt;
use App\Models\ProcessingRouteVersion;
use App\Models\ProcessingService as Capability;
use App\Models\VendorProfile;
use App\Models\VendorService;
use App\Models\VendorServiceRate;
use App\Models\WarehouseLocation;
use App\Modules\Processing\Services\ProcessingRoutes;
use App\Modules\Processing\Services\ProcessingService;
use Illuminate\Support\Str;

trait ProcessingFixtures
{
    use InventoryFixtures;

    protected PartyRole $vendor;

    protected VendorService $capability;

    protected WarehouseLocation $custody;

    protected ProcessingService $processing;

    protected ProcessingRouteVersion $route;

    protected function processingFixtures(): void
    {
        $this->inventoryFixtures();
        $party = Party::create(['code' => 'VEND-TEST', 'legal_name' => 'Dye house', 'party_kind' => 'organization', 'is_active' => true]);
        $this->vendor = PartyRole::create(['party_id' => $party->id, 'party_role_type_id' => PartyRoleType::where('code', 'vendor')->value('id'), 'is_active' => true]);
        VendorProfile::create(['party_role_id' => $this->vendor->id, 'payment_term_id' => PaymentTerm::firstOrFail()->id]);
        $this->capability = VendorService::create(['vendor_party_role_id' => $this->vendor->id, 'processing_service_id' => Capability::where('code', 'dyeing')->value('id'), 'is_active' => true]);
        VendorServiceRate::create(['vendor_service_id' => $this->capability->id, 'billing_unit_id' => $this->item->base_unit_id, 'unit_rate' => '2', 'currency_code' => 'PKR', 'effective_from' => today()->startOfYear()->format('Y-m-d'), 'approved_by' => $this->admin->id, 'rate_reason' => 'Signed processing contract']);
        $this->custody = WarehouseLocation::factory()->create(['location_type' => 'contractor', 'contractor_vendor_party_role_id' => $this->vendor->id]);
        $this->processing = app(ProcessingService::class);
        $r = app(ProcessingRoutes::class)->create($this->admin->id, ['route_code' => 'CUSTOM', 'name' => 'Configurable textile route', 'steps' => [['processing_service_id' => $this->capability->processing_service_id, 'input_item_id' => $this->item->id, 'output_item_id' => $this->item->id, 'planned_yield_ratio' => '1', 'permitted_normal_loss_fraction' => '0.1']]]);
        $this->route = app(ProcessingRoutes::class)->publish($this->admin->id, $r->id);
    }

    protected function processingDraft(string $kind, array $data): Document
    {
        return $this->processing->save($this->admin->id, $data + ['kind' => $kind, 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid()]);
    }

    protected function processingPost(Document $d, ?string $key = null): Document
    {
        return $this->processing->post($this->admin->id, $d->id, $key ?? (string) Str::uuid(), $d->fresh()->lock_version);
    }

    protected function job(bool $post = true, array $line = []): Document
    {
        $d = $this->processingDraft('job', ['processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '100', 'lines' => [array_replace(['vendor_service_id' => $this->capability->id], $line)]]);

        return $post ? $this->processingPost($d) : $d;
    }

    protected function dispatch(Document $job, int $position, string $qty = '100', bool $post = true): Document
    {
        $d = $this->processingDraft('dispatch', ['processing_job_document_id' => $job->id, 'vendor_party_role_id' => $this->vendor->id, 'lines' => [['processing_job_step_id' => ProcessingJob::findOrFail($job->id)->lines->first()->id, 'source_stock_position_id' => $position, 'contractor_location_id' => $this->custody->id, 'quantity' => $qty]]]);

        return $post ? $this->processingPost($d) : $d;
    }

    protected function processingReceipt(Document $dispatch, string $source = '100', string $out = '100', bool $post = true, array $line = []): Document
    {
        $h = ProcessingDispatch::findOrFail($dispatch->id);
        $d = $this->processingDraft('receipt', ['processing_job_document_id' => $h->processing_job_document_id, 'vendor_party_role_id' => $this->vendor->id, 'lines' => [array_replace(['processing_dispatch_line_id' => $h->lines->first()->id, 'disposition' => 'accepted', 'source_quantity' => $source, 'output_quantity' => $out, 'normal_loss_quantity' => '0', 'abnormal_loss_quantity' => '0', 'receiving_location_id' => $this->source->id, 'lot_number' => 'OUT-'.Str::random(10), 'quality_status' => 'released'], $line)]]);

        return $post ? $this->processingPost($d) : $d;
    }

    protected function contractorBill(Document $receipt, string $qty = '100', bool $post = true, ?string $reference = null): Document
    {
        $r = ProcessingReceipt::findOrFail($receipt->id)->lines->first();
        $d = $this->processingDraft('bill', ['vendor_party_role_id' => $this->vendor->id, 'supplier_invoice_number' => $reference ?? 'CB-'.Str::random(10), 'supplier_invoice_date' => $this->date, 'due_on' => today()->addDays(30)->format('Y-m-d'), 'lines' => [['processing_receipt_line_id' => $r->id, 'quantity' => $qty]]]);

        return $post ? $this->processingPost($d) : $d;
    }
}
