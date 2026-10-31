<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Document;
use App\Models\PackingOutputLine;
use App\Models\Permission;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FinishedGoodsFixtures;
use Tests\TestCase;

class FinishedGoodsWorkspaceTest extends TestCase
{
    use FinishedGoodsFixtures,RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finishedGoodsFixtures();
    }

    private function fixture(string $name, $response): void
    {
        if (! getenv('FINISHED_GOODS_BROWSER_FIXTURES')) {
            return;
        }$dir = storage_path('framework/testing/finished-goods-browser');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }file_put_contents($dir.'/'.$name.'.html', $response->getContent());
    }

    public function test_guided_screens_confirmation_costing_trace_and_private_pdfs_work(): void
    {
        $this->fixture('overview', $this->get('/finished-goods')->assertOk()->assertSee('finished-goods-sidebar-links'));
        $this->fixture('specifications', $this->get(route('finished-goods.specifications'))->assertOk()->assertSee('Publish specification'));
        $this->fixture('form', $this->get(route('finished-goods.create', ['spec' => $this->packSpec->id]))->assertOk()->assertSee('Confirm packing conversion'));
        $this->get(route('finished-goods.create', ['spec' => $this->packSpec->id, 'operation' => 'unpack']))->assertOk()->assertSee('Recover all original contents');
        $d = $this->packingData();
        $d['intent'] = 'confirm';
        $this->post(route('finished-goods.store'), $d)->assertRedirect()->assertSessionHasNoErrors();
        $doc = Document::where('idempotency_key', $d['idempotency_key'])->firstOrFail();
        $this->fixture('show', $this->get(route('finished-goods.show', $doc))->assertOk()->assertSee('Download PDF')->assertSee('Actual cost breakdown'));
        $this->fixture('costing', $this->get(route('finished-goods.costing', $doc))->assertOk()->assertSee('620.0000')->assertSee('62.0000'));
        $this->fixture('inventory', $this->get(route('finished-goods.inventory'))->assertOk()->assertSee('Current value PKR'));
        $this->fixture('batches', $this->get(route('finished-goods.batches'))->assertOk()->assertSee('Completed'));
        $this->get(route('finished-goods.index'))->assertOk();
        $this->get(route('finished-goods.costing', $this->finishedBatch))->assertOk()->assertSee('6000.0000');
        $out = PackingOutputLine::where('document_id', $doc->id)->first();
        $layer = StockCostLayer::where('receipt_stock_movement_id', $out->stock_movement_id)->first();
        $this->fixture('trace', $this->get(route('finished-goods.trace', $layer))->assertOk()->assertSee('Source production batches'));
        foreach ([route('finished-goods.pdf', $doc), route('finished-goods.costing.document', [$doc, 'pdf']), route('finished-goods.costing.document', [$this->finishedBatch, 'pdf'])] as $i => $url) {
            $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            if (getenv('TRANSACTION_PDF_FIXTURES')) {
                $dir = storage_path('framework/testing/transaction-pdf');
                if (! is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }file_put_contents($dir.'/finished-goods-'.$i.'.pdf', $pdf->getContent());
            }
        }
        $this->get(route('finished-goods.print', $doc))->assertOk()->assertDontSee('sidebar');
        $this->get(route('finished-goods.costing.document', [$this->finishedBatch, 'print']))->assertOk()->assertSee('Actual Batch / Packing Costing');
    }

    public function test_viewer_preparer_wholesale_and_cross_module_documents_are_protected(): void
    {
        $doc = $this->packing->confirm($this->admin->id, $this->packingData());
        $this->get(route('finished-goods.show', $this->finishedBatch))->assertForbidden();
        $role = AccessRole::create(['code' => 'goods-viewer', 'name' => 'Goods viewer', 'is_active' => true]);
        $role->permissions()->attach(Permission::where('code', 'finished-goods.view')->value('id'));
        $viewer = User::factory()->create();
        $viewer->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($viewer);
        $this->get('/finished-goods')->assertOk();
        $this->get(route('finished-goods.specifications'))->assertOk()->assertDontSee('Publish new specification version');
        $this->get(route('finished-goods.create'))->assertForbidden();
        $this->post(route('finished-goods.store'), $this->packingData())->assertForbidden();
        $role->permissions()->attach(Permission::where('code', 'finished-goods.manage')->value('id'));
        $viewer->unsetRelation('accessRoles');
        $this->get(route('finished-goods.create', ['spec' => $this->packSpec->id]))->assertOk()->assertDontSee('value="confirm"', false);
        $d = $this->packingData();
        $d['intent'] = 'confirm';
        $this->post(route('finished-goods.store'), $d)->assertForbidden();
        $this->actingAs(User::factory()->create());
        foreach (['/finished-goods', route('finished-goods.pdf', $doc), route('finished-goods.costing', $this->finishedBatch)] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_retained_reject_inspection_and_actual_disposal_use_existing_inventory(): void
    {
        $doc = $this->packing->confirm($this->admin->id, $this->packingData());
        $out = PackingOutputLine::where('document_id', $doc->id)->first();
        $lot = $out->position->stock_lot_id;
        $this->post(route('finished-goods.quality'), ['action' => 'quality', 'stock_lot_id' => $lot, 'quality_status' => 'rejected', 'reason' => 'Inspection found actual product damage.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $out->position->lot->fresh()->quality_status);
        $d = ['action' => 'damage', 'stock_lot_id' => $lot, 'stock_position_id' => $out->stock_position_id, 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'quantity' => '2', 'reason' => 'Two packed units physically destroyed with evidence.'];
        $this->post(route('finished-goods.quality'), $d)->assertRedirect()->assertSessionHasNoErrors();
        $count = StockMovement::count();
        $this->post(route('finished-goods.quality'), $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($count, StockMovement::count());
        $this->assertSame('8.000000', app(StockLedger::class)->onHand($out->stock_position_id));
        $this->get(route('finished-goods.costing', $doc))->assertOk()->assertSee('620.0000');
    }
}
