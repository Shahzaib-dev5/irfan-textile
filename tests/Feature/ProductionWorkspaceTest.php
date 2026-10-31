<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Document;
use App\Models\Permission;
use App\Models\ProductionReport;
use App\Models\StockPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ProductionFixtures;
use Tests\TestCase;

class ProductionWorkspaceTest extends TestCase
{
    use ProductionFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productionFixtures();
    }

    private function fixture(string $name, $response): void
    {
        if (! getenv('PRODUCTION_BROWSER_FIXTURES')) {
            return;
        }$dir = storage_path('framework/testing/production-browser');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }file_put_contents($dir.'/'.$name.'.html', $response->getContent());
    }

    public function test_guided_screens_forms_progress_pdf_and_confirmation_work(): void
    {
        $this->fixture('overview', $this->get('/production')->assertOk()->assertSee('production-sidebar-links')->assertSee('From processed fabric to finished suits'));
        $this->fixture('bom', $this->get(route('production.boms'))->assertOk()->assertSee('Publish new recipe version'));
        $this->fixture('batch', $this->get(route('production.batches.create'))->assertOk()->assertSee('Confirm production batch'));
        $this->post(route('production.batches.store'), ['intent' => 'confirm', 'document_date' => $this->date, 'idempotency_key' => $key = (string) Str::uuid(), 'bill_of_material_id' => $this->bom->id, 'planned_output_quantity' => '100'])->assertRedirect()->assertSessionHasNoErrors();
        $batch = Document::where('idempotency_key', $key)->firstOrFail();
        $this->opening('700');
        $this->fixture('report', $this->get(route('production.reports.create', ['batch' => $batch->id]))->assertOk()->assertSee('Actual materials consumed')->assertSee('Confirm production report'));
        $this->fixture('show', $this->get(route('production.show', $batch))->assertOk()->assertSee('Coordinate embroidery / finishing')->assertSee('Download PDF'));
        $this->fixture('progress', $this->get(route('production.progress', ['batch' => $batch->id]))->assertOk()->assertSee('Intermediate work-in-progress stock'));
        foreach (['production.batches.index', 'production.reports.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $pdf = $this->get(route('production.pdf', $batch))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get(route('production.print', $batch))->assertOk()->assertSee('Manufacturing Batch')->assertDontSee('sidebar');
        $position = StockPosition::whereHas('lot', fn ($q) => $q->where('item_id', $this->item->id))->firstOrFail();
        $data = $this->reportData($batch, $position->id, '300', '50');
        $data['intent'] = 'confirm';
        $this->post(route('production.reports.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $report = Document::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
        $reportPdf = $this->get(route('production.pdf', $report))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $reportPdf->getContent());
        $this->get(route('production.print', $report))->assertOk()->assertSee('Production Consumption and Output')->assertSee('300.000000');
        if (getenv('TRANSACTION_PDF_FIXTURES')) {
            $dir = storage_path('framework/testing/transaction-pdf');
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($dir.'/production_batch.pdf', $pdf->getContent());
            file_put_contents($dir.'/production_report.pdf', $reportPdf->getContent());
        }

    }

    public function test_validation_preserves_input_and_does_not_save_an_invalid_report(): void
    {
        $batch = $this->batch();
        $p = $this->position($this->opening('700'));
        $url = route('production.reports.create', ['batch' => $batch->id]);
        $d = $this->reportData($batch, $p, '1', '50');
        $d['intent'] = 'confirm';
        $d['report_note'] = 'KEEP THIS INPUT';
        $this->from($url)->post(route('production.reports.store'), $d)->assertRedirect($url)->assertSessionHasErrors('inventory');
        $this->get($url)->assertOk()->assertSee('KEEP THIS INPUT');
        $this->assertSame(0, ProductionReport::count());
    }

    public function test_preparer_viewer_wholesale_and_anonymous_permissions_are_enforced(): void
    {
        $batch = $this->batch();
        $p = $this->position($this->opening('700'));
        $role = AccessRole::create(['code' => 'production-viewer', 'name' => 'Production viewer']);
        $role->permissions()->attach(Permission::where('code', 'production.view')->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($user);
        $this->get('/production')->assertOk();
        $this->get(route('production.boms'))->assertOk()->assertDontSee('Publish new recipe version');
        $this->get(route('production.batches.create'))->assertForbidden();
        $this->post(route('production.reports.store'), $this->reportData($batch, $p))->assertForbidden();
        $role->permissions()->attach(Permission::where('code', 'production.manage')->value('id'));
        $user->unsetRelation('accessRoles');
        $this->get(route('production.batches.create'))->assertOk()->assertSee('Save for later')->assertDontSee('value="confirm"', false);
        $d = $this->reportData($batch, $p);
        $d['intent'] = 'confirm';
        $this->post(route('production.reports.store'), $d)->assertForbidden();
        $this->assertSame(0, ProductionReport::count());
        $wholesale = User::factory()->create();
        $this->actingAs($wholesale);
        foreach (['/production', route('production.pdf', $batch), route('production.print', $batch), route('production.progress')] as $url) {
            $this->get($url)->assertForbidden();
        }
        auth()->logout();
        $this->get('/production')->assertRedirect(route('login'));
    }

    public function test_bom_publication_and_saved_entry_cancellation_have_real_endpoints(): void
    {
        $this->post(route('production.boms.store'), ['product_variant_id' => $this->variant->id, 'output_quantity' => '1', 'lines' => [['component_item_id' => $this->item->id, 'component_name' => 'Updated recipe', 'net_quantity' => '6', 'planned_wastage_fraction' => '0']]])->assertRedirect()->assertSessionHasNoErrors();
        $d = $this->production->save($this->admin->id, ['kind' => 'batch', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'bill_of_material_id' => $this->bom->id, 'planned_output_quantity' => '20']);
        $this->post(route('production.action', [$d, 'cancel']), ['request_key' => (string) Str::uuid(), 'lock_version' => 0, 'reason' => 'Production schedule changed.'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $d->fresh()->status);
    }
}
