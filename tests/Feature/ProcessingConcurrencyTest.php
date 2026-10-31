<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\PartyOpenItem;
use App\Models\ProcessingDispatch;
use App\Models\ProcessingFabricFlow;
use App\Models\ProcessingJob;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Support\DatabaseRace;
use Tests\Support\ProcessingFixtures;
use Tests\TestCase;

class ProcessingConcurrencyTest extends TestCase
{
    use DatabaseRace,ProcessingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Only the isolated race database may be reset.');
        }RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->processingFixtures();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function tasks($a, $b, ?string $key = null): array
    {
        return [['action' => 'processing_post', 'document' => $a->id, 'key' => $key ?? (string) Str::uuid()], ['action' => 'processing_post', 'document' => $b->id, 'key' => $key ?? (string) Str::uuid()]];
    }

    public function test_fabric_out_retry_race_creates_one_job_dispatch_and_output_identity(): void
    {
        $data = ['kind' => 'out', 'intent' => 'confirm', 'request_key' => (string) Str::uuid(), 'document_date' => $this->date,
            'vendor_party_role_id' => $this->vendor->id, 'vendor_service_id' => $this->capability->id,
            'source_stock_position_id' => $this->position($this->opening()), 'quantity' => '100', 'contractor_location_id' => $this->custody->id,
            'fabric_type' => 'Lawn', 'color' => 'Blue'];
        $task = ['action' => 'fabric_confirm', 'input' => $data];
        $results = $this->race([$task, $task]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'result'), json_encode($results));
        $this->assertSame(1, ProcessingDispatch::count());
        $this->assertSame(1, ProcessingJob::count());
        $this->assertSame(3, StockMovement::count());
        $this->assertSame(1, ProcessingFabricFlow::count());
    }

    private function oneWinner(array $results): void
    {
        $out = array_column($results, 'result');
        sort($out);
        $this->assertSame(['posted', 'rejected'], $out, json_encode($results));
    }

    public function test_competing_direct_vendor_handovers_cannot_receive_or_transfer_twice(): void
    {
        $dispatch = $this->dispatch($this->job(), $this->position($this->opening()));
        $party = $this->vendor->party->replicate();
        $party->code = 'PRESS-RACE';
        $party->save();
        $role = $this->vendor->replicate();
        $role->party_id = $party->id;
        $role->save();
        $profile = $this->vendor->vendorProfile->replicate();
        $profile->party_role_id = $role->id;
        $profile->save();
        $capability = $this->capability->replicate();
        $capability->vendor_party_role_id = $role->id;
        $capability->save();
        $rate = $this->capability->rates->first()->replicate();
        $rate->vendor_service_id = $capability->id;
        $rate->save();
        $location = $this->custody->replicate();
        $location->code = 'PRESS-RACE';
        $location->contractor_vendor_party_role_id = $role->id;
        $location->save();
        $data = ['kind' => 'in', 'intent' => 'confirm', 'document_date' => $this->date,
            'processing_dispatch_line_id' => ProcessingDispatch::findOrFail($dispatch->id)->lines->first()->id,
            'destination' => 'vendor', 'disposition' => 'accepted', 'source_quantity' => '60', 'output_quantity' => '60',
            'quality_status' => 'released', 'laat_reference' => 'RACE-LAAT', 'fabric_type' => 'Lawn', 'color' => 'Blue',
            'next_vendor_party_role_id' => $role->id, 'next_vendor_service_id' => $capability->id, 'next_contractor_location_id' => $location->id];
        $this->oneWinner($this->race([
            ['action' => 'fabric_confirm', 'input' => $data + ['request_key' => (string) Str::uuid()]],
            ['action' => 'fabric_confirm', 'input' => $data + ['request_key' => (string) Str::uuid()]],
        ]));
        $this->assertSame(1, ProcessingFabricFlow::count());
        $this->assertSame(2, ProcessingDispatch::count());
        $this->assertSame('40.000000', $this->processing->outstanding(ProcessingDispatch::findOrFail($dispatch->id)->lines->first()));
        $next = ProcessingDispatch::where('vendor_party_role_id', $role->id)->firstOrFail()->lines->first();
        $this->assertSame('60.000000', $this->processing->outstanding($next));
        $this->assertTrue($this->processing->custodyMatches($next));
    }

    public function test_competing_direct_dispatch_confirmations_roll_back_the_losing_preparation(): void
    {
        $position = $this->position($this->opening());
        $job = $this->job();
        $step = ProcessingJob::find($job->id)->lines->first();
        $data = ['kind' => 'dispatch', 'document_date' => $this->date, 'processing_job_document_id' => $job->id, 'vendor_party_role_id' => $this->vendor->id, 'lines' => [['processing_job_step_id' => $step->id, 'source_stock_position_id' => $position, 'contractor_location_id' => $this->custody->id, 'quantity' => '60']]];
        $results = $this->race([
            ['action' => 'processing_confirm', 'input' => $data + ['idempotency_key' => (string) Str::uuid()]],
            ['action' => 'processing_confirm', 'input' => $data + ['idempotency_key' => (string) Str::uuid()]],
        ]);
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['posted', 'rejected'], $outcomes, json_encode($results));
        $this->assertSame(1, ProcessingDispatch::count());
        $this->assertSame(3, Document::count());
        $this->assertSame(3, StockMovement::count());
    }

    public function test_competing_dispatches_cannot_overdraw_the_job_or_stock(): void
    {
        $j = $this->job();
        $p = $this->position($this->opening());
        $a = $this->dispatch($j, $p, '60', false);
        $b = $this->dispatch($j, $p, '60', false);
        $this->oneWinner($this->race($this->tasks($a, $b)));
        $this->assertSame(3, StockMovement::count());
    }

    public function test_competing_receipts_cannot_close_the_same_custody_twice(): void
    {
        $d = $this->dispatch($this->job(), $this->position($this->opening()));
        $a = $this->processingReceipt($d, '60', '60', false);
        $b = $this->processingReceipt($d, '60', '60', false);
        $this->oneWinner($this->race($this->tasks($a, $b)));
        $this->assertSame('40.000000', $this->processing->outstanding(ProcessingDispatch::find($d->id)->lines->first()));
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_duplicate_dispatch_retry_has_one_effect(): void
    {
        $a = $this->dispatch($this->job(), $this->position($this->opening()), '100', false);
        $result = $this->race($this->tasks($a, $a, (string) Str::uuid()));
        $this->assertSame(['posted', 'posted'], array_column($result, 'result'), json_encode($result));
        $this->assertSame(3, StockMovement::count());
    }

    public function test_competing_contractor_bills_cannot_double_match(): void
    {
        $r = $this->processingReceipt($this->dispatch($this->job(), $this->position($this->opening())));
        $a = $this->contractorBill($r, '60', false);
        $b = $this->contractorBill($r, '60', false);
        $this->oneWinner($this->race($this->tasks($a, $b)));
        $this->assertSame(1, PartyOpenItem::count());
        $this->assertSame('120.0000', PartyOpenItem::first()->original_amount);
    }
}
