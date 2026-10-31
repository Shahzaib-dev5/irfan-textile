<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Document;
use App\Models\Item;
use App\Models\Permission;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingJob;
use App\Models\ProcessingReceipt;
use App\Models\StockMovement;
use App\Models\StockPosition;
use App\Models\User;
use App\Modules\Processing\Services\ProcessingRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProcessingFixtures;
use Tests\TestCase;

class ProcessingWorkspaceTest extends TestCase
{
    use ProcessingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processingFixtures();
    }

    private function limitedUser(array $permissions): User
    {
        $role = AccessRole::create(['code' => 'workspace-'.Str::random(8), 'name' => 'Workspace operator']);
        $role->permissions()->attach(Permission::whereIn('code', $permissions)->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);

        return $user;
    }

    private function browserFixture(string $name, $response): void
    {
        if (! getenv('PROCESSING_BROWSER_FIXTURES')) {
            return;
        }
        $directory = storage_path('framework/testing/processing-browser');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory.'/'.$name.'.html', $response->getContent());
    }

    public function test_separate_workspaces_render_and_lists_cannot_switch_type(): void
    {
        $job = $this->job();
        $dispatch = $this->dispatch($job, $this->position($this->opening()));
        $receipt = $this->processingReceipt($dispatch, '40', '40');
        $bill = $this->contractorBill($receipt, '20');
        foreach (['/processing', '/processing/jobs', '/processing/dispatches', '/processing/receipts', '/processing/bills', '/processing/payables', '/processing/custody', '/processing/reports', '/processing/types'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/processing/jobs?kind=bill')->assertOk()->assertViewHas('documents', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $job->id);
        $this->get(route('processing.jobs.show', $bill))->assertNotFound();
        $this->get('/processing')->assertSee('Before sending fabric')->assertSee('processing-sidebar-links')->assertSee('Stock with vendors')->assertSee('A bill is not a payment');
    }

    public function test_job_dispatch_receipt_bill_links_keep_the_original_context(): void
    {
        $this->browserFixture('overview', $this->get('/processing')->assertOk());
        $this->browserFixture('job', $this->get(route('processing.jobs.create', ['route' => $this->route->id]))->assertOk());
        $job = $this->job();
        $step = ProcessingJob::find($job->id)->lines->first();
        $this->get(route('processing.jobs.show', $job))->assertOk()->assertSee(route('processing.dispatches.create', ['job' => $job->id, 'step' => $step->id]));
        $p = $this->position($this->opening());
        $response = $this->get(route('processing.dispatches.create', ['job' => $job->id, 'step' => $step->id]))->assertOk()->assertSee('ASSIGNED VENDOR', false)->assertViewHas('stock', fn ($rows) => count($rows) === 1 && $rows[0]['id'] === $p)->assertViewHas('vendor', fn ($vendor) => $vendor->id === $this->vendor->id);
        $this->browserFixture('dispatch', $response);
        $dispatch = $this->dispatch($job, $p);
        $this->get(route('processing.dispatches.show', $dispatch))->assertOk()->assertSee(route('processing.fabric.in', ['dispatch' => $dispatch->id]));
        $response = $this->get(route('processing.receipts.create', ['dispatch' => $dispatch->id]))->assertOk()->assertViewHas('selectedJob', fn ($j) => $j->document_id === $job->id)->assertViewHas('dispatchLines', fn ($rows) => $rows[0]['available'] === '100.000000')->assertSee('Quantity check:');
        $this->browserFixture('receipt', $response);
        $receipt = $this->processingReceipt($dispatch, '40', '40');
        $this->get(route('processing.receipts.show', $receipt))->assertOk()->assertSee(route('processing.bills.create', ['receipt' => $receipt->id]));
        $response = $this->get(route('processing.bills.create', ['receipt' => $receipt->id]))->assertOk()->assertViewHas('billLines', fn ($rows) => $rows[0]['available'] === '40.000000')->assertSee('Contractor invoice number');
        $this->browserFixture('bill', $response);
    }

    public function test_dispatch_choices_exclude_other_items_held_expired_and_reserved_stock(): void
    {
        $valid = $this->position($this->opening('100'));
        $held = $this->position($this->opening('50', '10', true, null, ['quality_status' => 'held']));
        $expired = $this->position($this->opening('50'));
        StockPosition::find($expired)->lot->update(['expires_on' => today()->subDay()->format('Y-m-d')]);
        $other = Item::factory()->create(['base_unit_id' => $this->item->base_unit_id, 'category_id' => $this->item->category_id, 'is_stocked' => true, 'track_rolls' => false, 'is_active' => true]);
        $this->opening('50', '10', true, null, ['item_id' => $other->id]);
        $transfer = $this->transfer($valid, '30');
        $this->inventory->reserve($this->admin->id, $transfer->id, $transfer->lock_version);
        $job = $this->job();
        $this->get(route('processing.dispatches.create', ['job' => $job->id]))->assertOk()->assertViewHas('stock', fn ($rows) => count($rows) === 1 && $rows[0]['id'] === $valid && $rows[0]['available'] === '70.000000');
        $this->assertNotSame($valid, $held);
        $this->item->baseUnit->update(['is_active' => false]);
        $this->get(route('processing.dispatches.create', ['job' => $job->id]))->assertOk()->assertViewHas('stock', fn ($rows) => $rows === []);
    }

    public function test_roll_guidance_does_not_offer_reserved_physical_rolls(): void
    {
        $this->item->update(['track_rolls' => true]);
        $p = $this->position($this->opening('100', '10', true, null, ['roll_number' => 'WHOLE-ROLL']));
        $job = $this->job();
        $url = route('processing.dispatches.create', ['job' => $job->id]);
        $this->get($url)->assertOk()->assertViewHas('stock', fn ($rows) => $rows[0]['roll'] && $rows[0]['onHand'] === '100.000000')->assertSee('Use full roll quantity');
        $draft = $this->dispatch($job, $p, '40', false);
        $before = StockMovement::count();
        try {
            $this->processingPost($draft);
            $this->fail('Partial physical roll dispatched');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Dispatch the entire physical roll', $e->getMessage());
        }
        $this->assertSame($before, StockMovement::count());
        $this->assertSame('draft', $draft->fresh()->status);
        $transfer = $this->transfer($p, '100');
        $this->inventory->reserve($this->admin->id, $transfer->id, $transfer->lock_version);
        $this->get($url)->assertOk()->assertViewHas('stock', fn ($rows) => $rows === [])->assertSee('No eligible fabric for this stage');
    }

    public function test_partial_receipts_and_bills_offer_only_remaining_quantities(): void
    {
        $dispatch = $this->dispatch($this->job(), $this->position($this->opening()));
        $receipt = $this->processingReceipt($dispatch, '40', '40');
        $this->get(route('processing.receipts.create', ['dispatch' => $dispatch->id]))->assertOk()->assertViewHas('dispatchLines', fn ($rows) => $rows[0]['closed'] === '40.000000' && $rows[0]['available'] === '60.000000');
        $this->contractorBill($receipt, '15');
        $this->get(route('processing.bills.create', ['receipt' => $receipt->id]))->assertOk()->assertViewHas('billLines', fn ($rows) => $rows[0]['billed'] === '15.000000' && $rows[0]['available'] === '25.000000');
        $this->contractorBill($receipt, '25');
        $this->get(route('processing.bills.create', ['receipt' => $receipt->id]))->assertOk()->assertSee('This receipt has no unbilled charges');
        $this->processingReceipt($dispatch, '60', '60');
        $this->get(route('processing.receipts.create', ['dispatch' => $dispatch->id]))->assertOk()->assertSee('This dispatch has no outstanding fabric');
        $this->get(route('processing.dispatches.show', $dispatch))->assertDontSee('Receive fabric from this dispatch');
    }

    public function test_later_stage_choices_require_accepted_output_from_the_same_job(): void
    {
        $stage = ['processing_service_id' => $this->capability->processing_service_id, 'input_item_id' => $this->item->id, 'output_item_id' => $this->item->id, 'planned_yield_ratio' => '1', 'permitted_normal_loss_fraction' => '0.1'];
        $routes = app(ProcessingRoutes::class);
        $route = $routes->publish($this->admin->id, $routes->create($this->admin->id, ['route_code' => 'TWO', 'name' => 'Two stages', 'steps' => [$stage, $stage]])->id);
        $job = $this->processingPost($this->processingDraft('job', ['processing_route_version_id' => $route->id, 'planned_input_quantity' => '100', 'lines' => [['vendor_service_id' => $this->capability->id], ['vendor_service_id' => $this->capability->id]]]));
        $step = ProcessingJob::find($job->id)->lines->last();
        $p = $this->position($this->opening('200'));
        $url = route('processing.dispatches.create', ['job' => $job->id, 'step' => $step->id]);
        $this->get($url)->assertOk()->assertViewHas('stock', fn ($rows) => $rows === []);
        $receipt = $this->processingReceipt($this->dispatch($job, $p), '40', '40');
        $position = ProcessingReceipt::find($receipt->id)->lines->first()->output_stock_position_id;
        $this->get($url)->assertOk()->assertViewHas('stock', fn ($rows) => count($rows) === 1 && $rows[0]['id'] === $position && $rows[0]['available'] === '40.000000');
    }

    public function test_tampered_context_is_rejected_and_receipts_are_scoped_to_the_challan(): void
    {
        $a = $this->job();
        $b = $this->job();
        $p = $this->position($this->opening('200'));
        $da = $this->dispatch($a, $p);
        $db = $this->dispatch($b, $p);
        $line = ProcessingDispatch::find($da->id)->lines->first();
        $this->get(route('processing.receipts.create', ['dispatch' => $da->id]))->assertOk()->assertViewHas('dispatchLines', fn ($rows) => count($rows) === 1 && $rows[0]['id'] === $line->id);
        $this->get(route('processing.receipts.create', ['job' => $a->id, 'dispatch' => $db->id]))->assertNotFound();
        $this->get(route('processing.dispatches.create', ['job' => $a->id, 'step' => ProcessingJob::find($b->id)->lines->first()->id]))->assertNotFound();
        $this->get('/processing/jobs/create?route=999999')->assertNotFound();
    }

    public function test_new_routes_enforce_permissions_and_vendor_only_navigation_is_separate(): void
    {
        $read = $this->limitedUser(['processing.documents.view']);
        $this->actingAs($read)->get('/processing')->assertOk()->assertDontSee(route('vendor.index'));
        foreach (['jobs', 'dispatches', 'receipts', 'bills'] as $kind) {
            $this->get('/processing/'.$kind)->assertOk();
            $this->get('/processing/'.$kind.'/create')->assertForbidden();
            $this->post('/processing/'.$kind, [])->assertForbidden();
        }
        $vendor = $this->limitedUser(['processing.vendor.view']);
        $this->actingAs($vendor)->get('/processing/vendors')->assertOk()->assertSee('processing-sidebar-links')->assertDontSee(route('processing.jobs.index'));
        foreach (['', '/payables', '/jobs', '/dispatches', '/receipts', '/bills'] as $path) {
            $this->get('/processing'.$path)->assertForbidden();
        }
    }

    public function test_canonical_store_prevents_kind_spoofing_and_redirects_to_review(): void
    {
        $this->post(route('processing.jobs.store'), ['kind' => 'bill', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '100', 'lines' => [['vendor_service_id' => $this->capability->id]]])->assertSessionHasNoErrors()->assertRedirect(route('processing.jobs.show', Document::latest('id')->first()));
        $this->assertSame(1, ProcessingJob::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_validation_keeps_selected_challan_and_measured_quantities(): void
    {
        $dispatch = $this->dispatch($this->job(), $this->position($this->opening()));
        $line = ProcessingDispatch::find($dispatch->id)->lines->first();
        $url = route('processing.receipts.create', ['dispatch' => $dispatch->id]);
        $data = ['document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'processing_job_document_id' => ProcessingDispatch::find($dispatch->id)->processing_job_document_id, 'vendor_party_role_id' => $this->vendor->id, 'lines' => [['processing_dispatch_line_id' => $line->id, 'disposition' => 'accepted', 'source_quantity' => '40', 'output_quantity' => '35', 'normal_loss_quantity' => '0', 'abnormal_loss_quantity' => '0', 'receiving_location_id' => $this->source->id, 'lot_number' => 'KEEP-ME', 'quality_status' => 'released']]];
        $this->from($url)->post(route('processing.receipts.store'), $data)->assertRedirect($url)->assertSessionHasErrors('inventory');
        $this->get($url)->assertOk()->assertViewHas('selectedDispatch', fn ($d) => $d->document_id === $dispatch->id)->assertSee('KEEP-ME');
        $this->assertSame(0, ProcessingReceipt::count());
    }
}
