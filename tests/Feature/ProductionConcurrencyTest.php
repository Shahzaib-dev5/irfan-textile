<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\ProductionReport;
use App\Models\StockMovement;
use App\Modules\Production\Services\ProductionProgress;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Support\DatabaseRace;
use Tests\Support\ProductionFixtures;
use Tests\TestCase;

class ProductionConcurrencyTest extends TestCase
{
    use DatabaseRace,ProductionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Only the guarded disposable test database may be reset.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->productionFixtures();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_simultaneous_direct_retry_creates_one_material_conversion(): void
    {
        $p = $this->position($this->opening('600'));
        $batch = $this->batch();
        $data = $this->reportData($batch, $p, '600', '100', true);
        $task = ['action' => 'production_confirm', 'input' => $data];
        $r = $this->race([$task, $task]);
        $this->assertSame(['posted', 'posted'], array_column($r, 'result'), json_encode($r));
        $this->assertSame(1, ProductionReport::count());
        $this->assertSame(3, StockMovement::count());
        $this->assertSame(3, Document::count());
        $this->assertTrue(app(ProductionProgress::class)->final($batch->id));
    }

    public function test_competing_final_confirmations_cannot_overconsume_or_close_twice(): void
    {
        $p = $this->position($this->opening('600'));
        $batch = $this->batch();
        $a = $this->reportData($batch, $p, '600', '100', true);
        $b = $this->reportData($batch, $p, '600', '100', true);
        $r = $this->race([['action' => 'production_confirm', 'input' => $a], ['action' => 'production_confirm', 'input' => $b]]);
        $out = array_column($r, 'result');
        sort($out);
        $this->assertSame(['posted', 'rejected'], $out, json_encode($r));
        $this->assertSame(1, ProductionReport::count());
        $this->assertSame(3, Document::count());
        $this->assertSame(3, StockMovement::count());
    }
}
