<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackingOrder;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Support\DatabaseRace;
use Tests\Support\FinishedGoodsFixtures;
use Tests\TestCase;

class FinishedGoodsConcurrencyTest extends TestCase
{
    use DatabaseRace,FinishedGoodsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Only the guarded disposable test database may be reset.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->finishedGoodsFixtures();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_simultaneous_packing_replay_creates_one_conversion_and_container(): void
    {
        $d = $this->packingData('60');
        $task = ['action' => 'packing_confirm', 'input' => $d];
        $results = $this->race([$task, $task]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'result'), json_encode($results));
        $this->assertSame(1, PackingOrder::count());
        $this->assertSame(1, Package::count());
        $this->assertSame('40.000000', app(StockLedger::class)->onHand($this->suitPosition));
    }

    public function test_competing_packing_conversions_cannot_spend_the_same_suits_twice(): void
    {
        $results = $this->race([['action' => 'packing_confirm', 'input' => $this->packingData('60')], ['action' => 'packing_confirm', 'input' => $this->packingData('60')]]);
        $out = array_column($results, 'result');
        sort($out);
        $this->assertSame(['posted', 'rejected'], $out, json_encode($results));
        $this->assertSame(1, PackingOrder::count());
        $this->assertSame(1, Package::count());
        $this->assertSame('40.000000', app(StockLedger::class)->onHand($this->bagPosition));
    }
}
