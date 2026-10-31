<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\PartyOpenItem;
use App\Models\Permission;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingJob;
use App\Models\ProcessingReceipt;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ProcessingFixtures;
use Tests\TestCase;

class SimpleProcessingFlowTest extends TestCase
{
    use ProcessingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processingFixtures();
    }

    private function data(string $kind, array $data): array
    {
        return $data + ['intent' => 'confirm', 'kind' => $kind, 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid()];
    }

    private function sendData(string $kind, array $data): Document
    {
        $data = $this->data($kind, $data);
        $this->post(route('processing.'.['job' => 'jobs', 'dispatch' => 'dispatches', 'receipt' => 'receipts', 'bill' => 'bills'][$kind].'.store'), $data)->assertRedirect()->assertSessionHasNoErrors();

        return Document::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
    }

    private function jobData(): array
    {
        return ['processing_route_version_id' => $this->route->id, 'planned_input_quantity' => '100', 'lines' => [['vendor_service_id' => $this->capability->id]]];
    }

    public function test_admin_confirms_processing_partial_return_and_bill_then_downloads_all_four_pdfs(): void
    {
        $position = $this->position($this->opening());
        $job = $this->sendData('job', $this->jobData());
        $step = ProcessingJob::find($job->id)->lines->first();
        $dispatch = $this->sendData('dispatch', ['processing_job_document_id' => $job->id, 'vendor_party_role_id' => $this->vendor->id, 'lines' => [['processing_job_step_id' => $step->id, 'source_stock_position_id' => $position, 'contractor_location_id' => $this->custody->id, 'quantity' => '100']]]);
        $dispatchLine = ProcessingDispatch::find($dispatch->id)->lines->first();
        $receipt = $this->sendData('receipt', ['processing_job_document_id' => $job->id, 'vendor_party_role_id' => $this->vendor->id, 'lines' => [['processing_dispatch_line_id' => $dispatchLine->id, 'disposition' => 'accepted', 'source_quantity' => '40', 'output_quantity' => '38', 'normal_loss_quantity' => '2', 'abnormal_loss_quantity' => '0', 'receiving_location_id' => $this->source->id, 'lot_number' => 'SIMPLE-OUT', 'quality_status' => 'released']]]);
        $this->assertSame('60.000000', $this->processing->outstanding($dispatchLine));
        $bill = $this->sendData('bill', ['vendor_party_role_id' => $this->vendor->id, 'supplier_invoice_number' => 'SIMPLE-CB', 'supplier_invoice_date' => $this->date, 'due_on' => $this->date, 'lines' => [['processing_receipt_line_id' => ProcessingReceipt::find($receipt->id)->lines->first()->id, 'quantity' => '38']]]);
        $this->assertSame('76.0000', PartyOpenItem::first()->original_amount);
        $count = StockMovement::count();
        foreach ([$job, $dispatch, $receipt, $bill] as $doc) {
            $this->assertSame('posted', $doc->status);
            $this->get(route('processing.print', $doc))->assertOk()->assertSee('Confirmed')->assertSee('Prepared by')->assertDontSee('sidebar');
            $pdf = $this->get(route('processing.pdf', $doc))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            if (getenv('TRANSACTION_PDF_FIXTURES')) {
                $dir = storage_path('framework/testing/transaction-pdf');
                if (! is_dir($dir)) {
                    mkdir($dir, 0777, true);
                } file_put_contents($dir.'/processing-'.$this->processing->kind($doc).'.pdf', $pdf->getContent());
            }
        }
        $this->assertSame($count, StockMovement::count());
        $this->assertSame(1, PartyOpenItem::count());
    }

    public function test_processing_confirm_retry_uses_same_frozen_job_without_duplicate_posting(): void
    {
        $data = $this->data('job', $this->jobData());
        $first = $this->sendData('job', $data);
        $second = $this->sendData('job', $data);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ProcessingJob::count());
        $data['planned_input_quantity'] = '200';
        $this->post(route('processing.jobs.store'), $data)->assertSessionHasErrors('inventory');
        $this->assertSame('100.000000', ProcessingJob::find($first->id)->planned_input_quantity);
    }

    public function test_failed_confirm_does_not_leave_a_saved_dispatch_or_custody_movement(): void
    {
        $p = $this->position($this->opening('20'));
        $job = $this->job();
        $step = ProcessingJob::find($job->id)->lines->first();
        $docs = Document::count();
        $audit = AuditEvent::count();
        $movements = StockMovement::count();
        $this->post(route('processing.dispatches.store'), $this->data('dispatch', ['processing_job_document_id' => $job->id, 'vendor_party_role_id' => $this->vendor->id, 'lines' => [['processing_job_step_id' => $step->id, 'source_stock_position_id' => $p, 'contractor_location_id' => $this->custody->id, 'quantity' => '30']]]))->assertSessionHasErrors('inventory');
        $this->assertSame($docs, Document::count());
        $this->assertSame($audit, AuditEvent::count());
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(0, ProcessingDispatch::count());
    }

    public function test_manager_cannot_use_confirmation_to_bypass_posting_access(): void
    {
        $role = AccessRole::create(['code' => 'processing-prepare', 'name' => 'Prepare only']);
        $role->permissions()->attach(Permission::whereIn('code', ['processing.documents.view', 'processing.documents.manage'])->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($user);
        $data = $this->data('job', $this->jobData());
        $this->post(route('processing.jobs.store'), $data)->assertForbidden();
        $this->assertSame(0, ProcessingJob::count());
        $data['intent'] = 'save';
        $draft = $this->sendData('job', $data);
        $this->assertSame('draft', $draft->status);
        $this->get(route('processing.print', $draft))->assertOk()->assertSee('DRAFT - For review only.');
    }
}
