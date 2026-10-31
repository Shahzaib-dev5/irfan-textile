<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\SalesDispatch;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Support\DatabaseRace;
use Tests\Support\SalesFixtures;
use Tests\TestCase;

class SalesConcurrencyTest extends TestCase
{
    use DatabaseRace,SalesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Only guarded disposable test DB may be reset.');
        }RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->salesFixtures();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_concurrent_identical_dispatch_posts_exactly_once(): void
    {
        $o = $this->salesOrder('80');
        $task = ['action' => 'sales_confirm', 'input' => $this->dispatchData($o, '80')];
        $out = $this->race([$task, $task]);
        $this->assertSame(['posted', 'posted'], array_column($out, 'result'), json_encode($out));
        $this->assertSame(1, SalesDispatch::count());
        $this->assertSame('20.000000', app(StockLedger::class)->onHand($this->sellPosition));
    }

    public function test_competing_orders_cannot_dispatch_the_same_stock_twice(): void
    {
        $a = $this->salesOrder('80');
        $b = $this->salesOrder('80');
        $out = $this->race([['action' => 'sales_confirm', 'input' => $this->dispatchData($a, '80')], ['action' => 'sales_confirm', 'input' => $this->dispatchData($b, '80')]]);
        $results = array_column($out, 'result');
        sort($results);
        $this->assertSame(['posted', 'rejected'], $results, json_encode($out));
        $this->assertSame('20.000000', app(StockLedger::class)->onHand($this->sellPosition));
    }

    public function test_concurrent_invoices_cannot_bill_one_dispatch_twice(): void
    {
        $d = $this->salesDispatch($this->salesOrder('40'));
        $out = $this->race([['action' => 'sales_confirm', 'input' => $this->invoiceData($d)], ['action' => 'sales_confirm', 'input' => $this->invoiceData($d)]]);
        $results = array_column($out, 'result');
        sort($results);
        $this->assertSame(['posted', 'rejected'], $results, json_encode($out));
        $this->assertSame(1, SalesInvoice::count());
    }

    public function test_concurrent_credit_commitments_cannot_exceed_customer_limit(): void
    {
        CustomerProfile::whereKey($this->customer->id)->update(['credit_limit' => '5000']);
        $a = $this->salesData('order', [['item_id' => $this->variant->item_id, 'quantity' => '40', 'unit_price' => '100']]);
        $b = $a;
        $b['idempotency_key'] = (string) Str::uuid();
        $out = $this->race([['action' => 'sales_confirm', 'input' => $a], ['action' => 'sales_confirm', 'input' => $b]]);
        $results = array_column($out, 'result');
        sort($results);
        $this->assertSame(['posted', 'rejected'], $results, json_encode($out));
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_competing_sales_reservations_cannot_over_allocate_one_lot(): void
    {
        $a = $this->salesOrder('80');
        $b = $this->salesOrder('80');
        $tasks = [];
        foreach ([$a, $b] as $o) {
            $tasks[] = ['action' => 'sales_reserve', 'document' => $o->id, 'input' => ['sales_order_line_id' => SalesOrder::find($o->id)->lines->first()->id, 'stock_position_id' => $this->sellPosition, 'quantity' => '80']];
        }
        $out = $this->race($tasks);
        $results = array_column($out, 'result');
        sort($results);
        $this->assertSame(['posted', 'rejected'], $results, json_encode($out));
        $this->assertSame('20.000000', app(StockLedger::class)->available($this->sellPosition));
        $this->assertSame('100.000000',app(StockLedger::class)->onHand($this->sellPosition));
    }
}
