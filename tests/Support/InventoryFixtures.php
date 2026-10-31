<?php

namespace Tests\Support;

use App\Models\AccessRole;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Document;
use App\Models\FinancialYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;

trait InventoryFixtures
{
    protected User $admin;

    protected Item $item;

    protected WarehouseLocation $source;

    protected WarehouseLocation $destination;

    protected InventoryService $inventory;

    protected string $date;

    protected function inventoryFixtures(): void
    {
        $this->seed(DatabaseSeeder::class);
        Company::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->accessRoles()->attach(AccessRole::where('code', 'super-admin')->value('id'), ['assigned_by' => $this->admin->id]);
        $this->actingAs($this->admin);
        $this->date = today()->format('Y-m-d');
        $year = FinancialYear::create(['code' => 'FY-'.today()->year, 'starts_on' => today()->startOfYear()->format('Y-m-d'), 'ends_on' => today()->endOfYear()->format('Y-m-d'), 'is_closed' => false]);
        AccountingPeriod::create(['financial_year_id' => $year->id, 'code' => 'P1', 'starts_on' => $year->starts_on, 'ends_on' => $year->ends_on, 'status' => 'open']);
        $category = ItemCategory::factory()->create();
        $this->item = Item::factory()->create(['category_id' => $category->id, 'base_unit_id' => Unit::where('code', 'M')->value('id'), 'sku' => 'GREY-TEST', 'item_type' => 'grey_fabric', 'is_stocked' => true, 'track_rolls' => false, 'is_active' => true]);
        $this->source = WarehouseLocation::factory()->create();
        $this->destination = WarehouseLocation::factory()->create();
        $this->inventory = app(InventoryService::class);
    }

    protected function opening(string $quantity = '100', string $cost = '10', bool $post = true, ?string $lot = null, array $overrides = []): Document
    {
        $line = array_replace(['item_id' => $this->item->id, 'warehouse_location_id' => $this->source->id, 'lot_number' => $lot ?? 'LOT-'.Str::random(8), 'quality_status' => 'released', 'quantity_delta' => $quantity, 'positive_unit_cost_pkr' => $cost], $overrides);
        $doc = $this->inventory->save($this->admin->id, ['kind' => 'adjustment', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'adjustment_reason' => 'opening', 'reference' => 'Signed opening count OC-001', 'explanation' => 'Verified physical stock and supported acquisition cost.', 'lines' => [$line]]);

        return $post ? $this->inventory->post($this->admin->id, $doc->id, (string) Str::uuid(), $doc->lock_version) : $doc;
    }

    protected function adjustment(int $position, string $quantity, string $reason = 'damage', ?string $cost = null, ?string $count = null): Document
    {
        return $this->inventory->save($this->admin->id, ['kind' => 'adjustment', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'adjustment_reason' => $reason, 'explanation' => 'Physical verification with supervisor approval.', 'lines' => [['stock_position_id' => $position, 'quantity_delta' => $quantity, 'positive_unit_cost_pkr' => $cost, 'counted_quantity' => $count]]]);
    }

    protected function transfer(int $position, string $quantity, ?int $target = null): Document
    {
        return $this->inventory->save($this->admin->id, ['kind' => 'transfer', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'explanation' => 'Move stock with signed warehouse handover.', 'lines' => [['source_stock_position_id' => $position, 'destination_location_id' => $target ?? $this->destination->id, 'quantity' => $quantity]]]);
    }

    protected function postDocument(Document $doc, ?string $key = null): Document
    {
        return $this->inventory->post($this->admin->id, $doc->id, $key ?? (string) Str::uuid(), $doc->fresh()->lock_version);
    }

    protected function position(Document $doc): int
    {
        return $doc->adjustment->lines()->firstOrFail()->stock_position_id;
    }
}
