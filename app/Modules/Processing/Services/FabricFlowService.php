<?php

namespace App\Modules\Processing\Services;

use App\Models\Document;
use App\Models\Item;
use App\Models\PartyRole;
use App\Models\ProcessingDispatchLine;
use App\Models\ProcessingFabricFlow;
use App\Models\ProcessingJob;
use App\Models\ProcessingReceipt;
use App\Models\StockPosition;
use App\Models\VendorService;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Support\Decimal as D;
use App\Modules\Processing\Http\Requests\FabricFlowRequest;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** One operator action; existing immutable documents remain the stock/accounting authority. */
class FabricFlowService
{
    public function __construct(private ProcessingService $processing, private ProcessingRoutes $routes) {}

    private function key(string $root, string $part): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'textile:fabric:'.$root.':'.$part);
    }

    public function specifications(ProcessingDispatchLine $line): array
    {
        $specs = [];
        $visited = [];
        while (! isset($visited[$line->document_id])) {
            $visited[$line->document_id] = true;
            $flow = ProcessingFabricFlow::where('document_id', $line->document_id)->orWhere('onward_document_id', $line->document_id)->first();
            if (! $flow) {
                break;
            }
            foreach (['fabric_type', 'color', 'design_reference', 'component_type'] as $attribute) {
                if (! empty($flow->details[$attribute]) && ! isset($specs[$attribute])) {
                    $specs[$attribute] = $flow->details[$attribute];
                }
            }
            if ($flow->kind === 'out') {
                break;
            }
            $line = ProcessingDispatchLine::findOrFail($flow->details['processing_dispatch_line_id']);
        }

        return $specs;
    }

    /** Reuse a vendor custody location or create the one logical location needed by Fabric Out. */
    private function contractorLocation(int $actor, int $vendorRole, ?int $requested = null): int
    {
        if ($requested) {
            $location = WarehouseLocation::whereKey($requested)->where('location_type', 'contractor')->where('contractor_vendor_party_role_id', $vendorRole)->where('is_active', true)->first();
            if ($location) {
                return $location->id;
            }
            Guard::fail('The selected contractor location does not belong to this vendor.');
        }
        $existing = WarehouseLocation::where('location_type', 'contractor')->where('contractor_vendor_party_role_id', $vendorRole)->where('is_active', true)->first();
        if ($existing) {
            return $existing->id;
        }
        $vendor = PartyRole::with('party')->findOrFail($vendorRole);
        $code = 'VENDOR-'.$vendorRole;
        $warehouse = Warehouse::firstOrCreate(['code' => $code], ['name' => mb_substr($vendor->party->legal_name.' — Vendor custody', 0, 120), 'address' => null, 'is_active' => true]);
        if (! $warehouse->is_active) {
            Guard::fail('The automatic vendor custody warehouse is inactive.');
        }
        $location = WarehouseLocation::firstOrCreate(['warehouse_id' => $warehouse->id, 'code' => 'CUSTODY'], ['name' => 'Vendor-held fabric', 'location_type' => 'contractor', 'contractor_vendor_party_role_id' => $vendorRole, 'is_active' => true]);
        app(AuditLogger::class)->record('warehouses', $warehouse->id, 'vendor_custody_auto_created', null, ['vendor_party_role_id' => $vendorRole, 'location_id' => $location->id], $actor);

        return $location->id;
    }

    public function execute(int $actor, array $input): ProcessingFabricFlow
    {
        $d = validator($input, FabricFlowRequest::fields())->validate();
        $intent = $d['intent'];
        unset($d['intent']);
        // Form submissions include blank optional fields. Canonicalize for saved-entry confirmation.
        $d = array_filter($d, fn ($v) => $v !== null && $v !== '');
        $d = array_map(fn ($v) => (string) $v, $d);
        ksort($d);
        $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $d, $intent, $hash) {
            Guard::company();
            Guard::actor($actor, 'processing.documents.view');
            Guard::actor($actor, 'processing.documents.manage');
            $flow = ProcessingFabricFlow::where('request_key', $d['request_key'])->lockForUpdate()->first();
            if ($flow && ! hash_equals($flow->input_hash, $hash)) {
                Guard::fail('This request key belongs to different fabric details. Start a new entry.');
            }
            if ($flow?->status === 'posted') {
                Guard::actor($actor, $d['kind'] === 'out' ? 'processing.dispatches.post' : 'processing.receipts.post');

                return $flow;
            }
            $flow ??= ProcessingFabricFlow::create(['request_key' => $d['request_key'], 'kind' => $d['kind'], 'status' => 'draft', 'input_hash' => $hash, 'details' => $d, 'created_by' => $actor]);
            if ($intent === 'save') {
                return $flow;
            }
            $onward = null;
            if ($d['kind'] === 'out') {
                $document = $this->out($actor, $d, $d['request_key']);
            } else {
                [$document, $onward] = $this->receive($actor, $d);
            }
            $flow->update(['status' => 'posted', 'document_id' => $document->id, 'onward_document_id' => $onward?->id]);
            app(AuditLogger::class)->record('documents', $document->id, 'fabric_flow_confirmed', null, ['flow_id' => $flow->id, 'input_hash' => $hash, 'details' => $d, 'onward_document_id' => $onward?->id], $actor, $document->id);

            return $flow->fresh();
        }, 3);
    }

    private function output(int $actor, Item $source, VendorService $capability, array $d): Item
    {
        if (! $source->isFabric()) {
            Guard::fail('Select a stocked grey or processed fabric item. Suit assembly remains in Production.');
        }
        $service = $capability->processingService;
        if (str_contains(strtolower($service->code), 'dyeing') && ($service->billingUnit->code !== 'M' || $source->baseUnit->code !== 'M')) {
            Guard::fail('Dyeing physical quantities and billing must use meters.');
        }
        if (str_contains(strtolower($service->code), 'embroidery') && $service->billingUnit->code !== 'SUIT') {
            Guard::fail('Use an embroidery service with a published per-suit (SUIT) rate. Existing piece-rate contracts remain available in the advanced workflow.');
        }
        if (str_contains(strtolower($service->code), 'dyeing') && empty($d['color'])) {
            Guard::fail('Enter the target dyeing color or shade.');
        }
        if (str_contains(strtolower($service->code), 'embroidery') && (empty($d['component_type']) || empty($d['design_reference']))) {
            Guard::fail('Enter the embroidery component (for example dupatta) and design reference.');
        }
        $identity = [$source->id, $service->id, $d['fabric_type'], $d['color'] ?? '', $d['design_reference'] ?? '', $d['component_type'] ?? ''];
        $sku = 'PF-'.strtoupper(substr(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), 0, 32));
        $existing = Item::where('sku', $sku)->first();
        if ($existing) {
            if (! $existing->is_active || $existing->base_unit_id !== $source->base_unit_id || $existing->item_type !== 'processed_fabric' || ! $existing->is_stocked || $existing->track_rolls !== $source->track_rolls) {
                Guard::fail('The derived processed fabric identity is inactive or incompatible. Review its catalog record.');
            }

            return $existing;
        }
        $output = $source->replicate();
        $output->sku = $sku;
        $output->name = mb_substr(implode(' / ', array_filter([$service->name, $d['fabric_type'], $d['color'] ?? '', $d['component_type'] ?? '', $d['design_reference'] ?? '', $source->sku])), 0, 200);
        $output->item_type = 'processed_fabric';
        $output->description = 'Derived from '.$source->sku.' by '.$service->name.'. '.$source->description;
        $output->save();
        if ($spec = $source->fabricSpecification) {
            $copy = $spec->replicate();
            $copy->item_id = $output->id;
            $copy->save();
        }
        foreach ($source->conversions as $conversion) {
            $copy = $conversion->replicate();
            $copy->item_id = $output->id;
            $copy->save();
        }
        app(AuditLogger::class)->record('items', $output->id, 'processing_output_created', null, ['source_item_id' => $source->id, 'identity' => $identity, 'sku' => $sku], $actor);

        return $output;
    }

    private function out(int $actor, array $d, string $key): Document
    {
        $position = StockPosition::with('lot.item.baseUnit')->findOrFail($d['source_stock_position_id']);
        $capability = VendorService::with('processingService.billingUnit')->findOrFail($d['vendor_service_id']);
        if ($capability->vendor_party_role_id !== (int) $d['vendor_party_role_id']) {
            Guard::fail('Choose a service belonging to the selected vendor.');
        }
        $d['contractor_location_id'] = $this->contractorLocation($actor, $capability->vendor_party_role_id, $d['contractor_location_id'] ?? null);
        $output = $this->output($actor, $position->lot->item, $capability, $d);
        $route = $this->routes->create($actor, ['route_code' => 'FO-'.str_replace('-', '', $key), 'name' => 'Fabric Out: '.$capability->processingService->name, 'steps' => [['processing_service_id' => $capability->processing_service_id, 'input_item_id' => $position->lot->item_id, 'output_item_id' => $output->id, 'planned_yield_ratio' => '1', 'permitted_normal_loss_fraction' => bcdiv(D::input($d['normal_loss_percent'] ?? '0'), '100', 10)]]]);
        $this->routes->publish($actor, $route->id);
        $job = $this->processing->confirm($actor, ['kind' => 'job', 'document_date' => $d['document_date'], 'idempotency_key' => $this->key($key, 'job'), 'processing_route_version_id' => $route->id, 'manufacturing_batch_document_id' => $d['manufacturing_batch_document_id'] ?? null, 'planned_input_quantity' => $d['quantity'], 'instructions' => $d['instructions'] ?? null, 'lines' => [['vendor_service_id' => $capability->id, 'agreed_unit_rate_pkr' => $d['agreed_unit_rate_pkr'] ?? null, 'override_reason' => $d['override_reason'] ?? null]]]);

        return $this->processing->confirm($actor, ['kind' => 'dispatch', 'document_date' => $d['document_date'], 'idempotency_key' => $this->key($key, 'dispatch'), 'processing_job_document_id' => $job->id, 'vendor_party_role_id' => $capability->vendor_party_role_id, 'lines' => [['processing_job_step_id' => ProcessingJob::findOrFail($job->id)->lines->first()->id, 'source_stock_position_id' => $position->id, 'contractor_location_id' => $d['contractor_location_id'], 'quantity' => $d['quantity']]]]);
    }

    private function receive(int $actor, array $d): array
    {
        $line = ProcessingDispatchLine::with('dispatch.job', 'step.service', 'step.billingUnit', 'step.inputItem', 'contractor')->lockForUpdate()->findOrFail($d['processing_dispatch_line_id']);
        $specs = $this->specifications($line);
        foreach ($specs as $attribute => $value) {
            if (! empty($d[$attribute]) && $d[$attribute] !== $value) {
                Guard::fail('Returned '.$attribute.' must match the agreed Fabric Out identity.');
            }
        }
        $d = array_replace($specs, $d);
        $transfer = $d['destination'] === 'vendor';
        if ($transfer && (int) $d['next_vendor_party_role_id'] === $line->dispatch->vendor_party_role_id) {
            Guard::fail('Select a different vendor for the next service.');
        }
        if (str_contains(strtolower($line->step->service->code), 'embroidery') && $d['disposition'] === 'accepted' && (empty($d['component_count']) || empty($d['component_type']))) {
            Guard::fail('Record the actual returned component type and count for this laat.');
        }
        $summary = 'Laat '.$d['laat_reference'].'; '.($d['component_count'] ?? 'Uncounted').' '.($d['component_type'] ?? 'fabric').'; billable '.($d['accepted_service_quantity'] ?? 'measured output').' '.$line->step->billingUnit->code.'. ';
        $receiptInput = ['kind' => 'receipt', 'document_date' => $d['document_date'], 'idempotency_key' => $this->key($d['request_key'], 'receipt'), 'processing_job_document_id' => $line->dispatch->processing_job_document_id, 'vendor_party_role_id' => $line->dispatch->vendor_party_role_id, 'contractor_reference' => $d['laat_reference'], 'remarks' => mb_substr($summary.($d['remarks'] ?? ''), 0, 1000), 'lines' => [[
            'processing_dispatch_line_id' => $line->id, 'disposition' => $d['disposition'], 'source_quantity' => $d['source_quantity'], 'output_quantity' => $d['output_quantity'],
            'normal_loss_quantity' => $d['normal_loss_quantity'] ?? '0', 'abnormal_loss_quantity' => $d['abnormal_loss_quantity'] ?? '0', 'accepted_service_quantity' => $d['accepted_service_quantity'] ?? null,
            'receiving_location_id' => $transfer ? $line->contractor->warehouse_location_id : ($d['receiving_location_id'] ?? 0),
            'quality_status' => $d['quality_status'] ?? 'pending', 'lot_number' => 'FI-'.str_replace('-', '', $d['request_key']), 'roll_number' => $d['roll_number'] ?? null,
        ]]];
        if (! $transfer) {
            return [$this->processing->confirm($actor, $receiptInput), null];
        }

        return $this->processing->handover($actor, $receiptInput, function (Document $receipt) use ($actor, $d, $line) {
            $received = ProcessingReceipt::findOrFail($receipt->id)->lines->first();
            $prior = $this->specifications($line);

            return $this->out($actor, [
                'document_date' => $d['document_date'], 'source_stock_position_id' => $received->output_stock_position_id, 'quantity' => $received->output_quantity,
                'vendor_party_role_id' => $d['next_vendor_party_role_id'], 'vendor_service_id' => $d['next_vendor_service_id'], 'contractor_location_id' => $d['next_contractor_location_id'],
                'agreed_unit_rate_pkr' => $d['next_agreed_unit_rate_pkr'] ?? null, 'override_reason' => $d['next_override_reason'] ?? null, 'normal_loss_percent' => $d['next_normal_loss_percent'] ?? '0',
                'fabric_type' => $d['fabric_type'] ?? $prior['fabric_type'] ?? $line->step->inputItem->name,
                'color' => $d['color'] ?? $prior['color'] ?? '', 'design_reference' => $d['design_reference'] ?? $prior['design_reference'] ?? '',
                'component_type' => $d['component_type'] ?? $prior['component_type'] ?? '', 'instructions' => $d['instructions'] ?? '',
                'manufacturing_batch_document_id' => $line->dispatch->job->manufacturing_batch_document_id,
            ], $this->key($d['request_key'], 'onward'));
        });
    }
}
