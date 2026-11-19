<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Document;
use App\Models\Permission;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockPosition;
use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\InventoryFixtures;
use Tests\TestCase;

class DirectWarehouseTransferTest extends TestCase
{
    use InventoryFixtures, RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Use the isolated test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventoryFixtures();
    }

    private function payload(int $position): array
    {
        return ['kind' => 'transfer', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(),
            'explanation' => 'Actual warehouse handover', 'lines' => [['source_stock_position_id' => $position,
                'destination_location_id' => $this->destination->id, 'quantity' => '20']]];
    }

    public function test_http_transfer_posts_immediately_and_exact_replay_does_not_move_stock_twice(): void
    {
        $source = $this->position($this->opening());
        $data = $this->payload($source);
        $this->get(route('inventory.documents.create', ['kind' => 'transfer']))->assertOk()->assertSee('Confirm transfer')->assertDontSee('Save draft for review');
        $this->post(route('inventory.documents.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $transfer = Document::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
        $this->assertSame('posted', $transfer->status);
        $this->assertSame('80.000000', StockBalance::findOrFail($source)->on_hand_quantity);
        $this->assertSame('20.000000', $transfer->transfer->lines->first()->destination->balance->on_hand_quantity);
        $this->post(route('inventory.documents.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, StockMovement::where('document_id', $transfer->id)->count());
        $data['lines'][0]['quantity'] = '21';
        $this->post(route('inventory.documents.store'), $data)->assertSessionHasErrors();
        $this->assertSame('80.000000', StockBalance::findOrFail($source)->on_hand_quantity);
    }

    public function test_post_failure_rolls_back_draft_positions_and_movements(): void
    {
        $source = $this->position($this->opening());
        $data = $this->payload($source);
        $positions = StockPosition::count();
        $ledger = Mockery::mock(StockLedger::class)->makePartial();
        $ledger->shouldReceive('validateRolls')->once()->andThrow(ValidationException::withMessages(['inventory' => 'Injected final posting failure']));
        $service = new InventoryService($ledger, app(AuditLogger::class));
        try {
            $service->confirmTransfer($this->admin->id, $data);
            $this->fail('Expected final posting failure.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Injected', $exception->getMessage());
        }
        $this->assertFalse(Document::where('idempotency_key', $data['idempotency_key'])->exists());
        $this->assertSame($positions, StockPosition::count());
        $this->assertSame(1, StockMovement::count());
        $this->assertSame('100.000000', StockBalance::findOrFail($source)->on_hand_quantity);
    }

    public function test_manage_permission_alone_cannot_confirm_a_transfer(): void
    {
        $source = $this->position($this->opening());
        $role = AccessRole::create(['code' => 'transfer-preparer', 'name' => 'Transfer preparer', 'is_active' => true]);
        $role->permissions()->attach(Permission::whereIn('code', ['inventory.view', 'inventory.manage'])->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($user)->post(route('inventory.documents.store'), $this->payload($source))->assertForbidden();
        $this->get(route('inventory.documents.create', ['kind' => 'transfer']))->assertForbidden();
        $this->assertSame(1, Document::count());
    }

    public function test_existing_transfer_draft_can_be_confirmed_from_edit_form(): void
    {
        $source = $this->position($this->opening());
        $data = $this->payload($source);
        $draft = $this->inventory->save($this->admin->id, $data);
        $data['lock_version'] = $draft->lock_version;
        $this->put(route('inventory.documents.update', $draft), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('posted', $draft->fresh()->status);
        $this->assertSame('80.000000',StockBalance::findOrFail($source)->on_hand_quantity);
    }
}
