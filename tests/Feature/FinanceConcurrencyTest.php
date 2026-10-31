<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Support\DatabaseRace;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class FinanceConcurrencyTest extends TestCase
{
    use DatabaseRace,FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Only the guarded disposable test database may be reset.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->financeFixtures();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_concurrent_same_payment_has_one_journal_and_allocation(): void
    {
        $item = $this->billItem();
        $task = ['action' => 'finance_confirm', 'input' => $this->paymentData('600', $item->id)];
        $out = $this->race([$task, $task]);
        $this->assertSame(['posted', 'posted'], array_column($out, 'result'), json_encode($out));
        $this->assertSame(1, Payment::count());
        $this->assertSame('400.0000', $this->finance->outstanding($item->id));
    }

    public function test_competing_payments_cannot_over_settle_one_supplier_bill(): void
    {
        $item = $this->billItem();
        $out = $this->race([['action' => 'finance_confirm', 'input' => $this->paymentData('600', $item->id)], ['action' => 'finance_confirm', 'input' => $this->paymentData('600', $item->id)]]);
        $results = array_column($out, 'result');
        sort($results);
        $this->assertSame(['posted', 'rejected'], $results, json_encode($out));
        $this->assertSame(1, Payment::count());
        $this->assertSame('400.0000', $this->finance->outstanding($item->id));
    }
}
