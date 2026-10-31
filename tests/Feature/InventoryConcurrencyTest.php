<?php

namespace Tests\Feature;

use App\Models\JournalLine;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryInquiry;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Support\InventoryFixtures;
use Tests\TestCase;

/** Committed fixtures are required so independent worker connections can see them. */
class InventoryConcurrencyTest extends TestCase
{
    use InventoryFixtures, \Tests\Support\DatabaseRace;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Concurrent tests may reset only the isolated irfan_textile_phase3_test database.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->inventoryFixtures();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_competing_posters_cannot_overspend_stock(): void
    {
        $position = $this->position($this->opening('60'));
        $first = $this->adjustment($position, '-40');
        $second = $this->adjustment($position, '-40');
        $results = $this->race([['action' => 'post', 'document' => $first->id, 'key' => (string) Str::uuid()], ['action' => 'post', 'document' => $second->id, 'key' => (string) Str::uuid()]]);
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['posted', 'rejected'], $outcomes, json_encode($results));
        $this->assertSame('20.000000', app(StockLedger::class)->onHand($position));
        $this->assertSame(2, StockMovement::count());
        $this->assertSame([], app(InventoryInquiry::class)->reconciliation());
    }

    public function test_identical_simultaneous_post_retries_create_one_ledger_and_journal(): void
    {
        $draft = $this->opening('50', '10', false);
        $key = (string) Str::uuid();
        $task = ['action' => 'post', 'document' => $draft->id, 'key' => $key];
        $results = $this->race([$task, $task]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'result'), json_encode($results));
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(2, JournalLine::count());
        $this->assertSame('50.000000', app(StockLedger::class)->onHand($this->position($draft)));
    }

    public function test_competing_reservations_cannot_overcommit_stock(): void
    {
        $position = $this->position($this->opening('60'));
        $first = $this->transfer($position, '40');
        $second = $this->transfer($position, '40');
        $results = $this->race([['action' => 'reserve', 'document' => $first->id], ['action' => 'reserve', 'document' => $second->id]]);
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['posted', 'rejected'], $outcomes, json_encode($results));
        $this->assertSame(1, StockReservation::count());
        $this->assertSame('20.000000', app(StockLedger::class)->available($position));
    }
}
