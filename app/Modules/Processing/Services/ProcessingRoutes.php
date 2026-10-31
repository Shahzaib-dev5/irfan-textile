<?php

namespace App\Modules\Processing\Services;

use App\Models\Item;
use App\Models\ProcessingRouteStep;
use App\Models\ProcessingRouteVersion;
use App\Models\ProcessingService;
use App\Models\Unit;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Support\Decimal as D;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProcessingRoutes
{
    public function service(int $actor, array $input): ProcessingService
    {
        return DB::transaction(function () use ($actor, $input) {
            Guard::company();
            Guard::actor($actor, 'processing.routes.manage');
            $d = validator($input, ['code' => 'required|alpha_dash|max:40|unique:processing_services,code', 'name' => 'required|string|max:100', 'billing_unit_id' => 'required|exists:units,id', 'description' => 'nullable|string|max:500'])->validate();
            $u = Unit::findOrFail($d['billing_unit_id']);
            if (! $u->is_active || (in_array(strtolower($d['code']), ['dyeing', 'combined-dan-dyeing']) && $u->code !== 'M')) {
                Guard::fail('Dyeing services require the active meter unit.');
            }
            $s = ProcessingService::create($d + ['is_active' => true]);
            app(AuditLogger::class)->record('processing_services', $s->id, 'created', null, $d, $actor);

            return $s;
        }, 3);
    }

    public function create(int $actor, array $input): ProcessingRouteVersion
    {
        return DB::transaction(function () use ($actor, $input) {
            Guard::company();
            Guard::actor($actor, 'processing.routes.manage');
            $d = validator($input, ['route_code' => 'required|alpha_dash|max:40', 'name' => 'required|string|max:150', 'steps' => 'required|array|min:1|max:30', 'steps.*.processing_service_id' => 'required|exists:processing_services,id', 'steps.*.input_item_id' => 'required|exists:items,id', 'steps.*.output_item_id' => 'required|exists:items,id', 'steps.*.planned_yield_ratio' => 'required|numeric|gt:0', 'steps.*.permitted_normal_loss_fraction' => 'required|numeric|min:0|lt:1'])->validate();
            $d['steps'] = array_values($d['steps']);
            $code = strtoupper($d['route_code']);
            $route = ProcessingRouteVersion::create(['route_code' => $code, 'name' => $d['name'], 'version_number' => 1 + (int) ProcessingRouteVersion::where('route_code', $code)->max('version_number')]);
            foreach ($d['steps'] as $i => $s) {
                $s = Arr::only($s, ['processing_service_id', 'input_item_id', 'output_item_id', 'planned_yield_ratio', 'permitted_normal_loss_fraction']);
                $s['planned_yield_ratio'] = D::input($s['planned_yield_ratio'], 10);
                if (bccomp($s['planned_yield_ratio'], '10000000000', 10) >= 0) {
                    Guard::fail('Planned yield exceeds DECIMAL(20,10) precision.');
                }
                $s['permitted_normal_loss_fraction'] = D::input($s['permitted_normal_loss_fraction'], 10);
                ProcessingRouteStep::create($s + ['processing_route_version_id' => $route->id, 'step_number' => $i + 1]);
            }
            app(AuditLogger::class)->record('processing_route_versions', $route->id, 'draft_created', null, $route->load('steps')->toArray(), $actor);

            return $route;
        }, 3);
    }

    public function publish(int $actor, int $id): ProcessingRouteVersion
    {
        return DB::transaction(function () use ($actor, $id) {
            Guard::company();
            Guard::actor($actor, 'processing.routes.manage');
            $r = ProcessingRouteVersion::with('steps')->lockForUpdate()->findOrFail($id);
            if ($r->state === 'published') {
                return $r;
            }
            if ($r->state !== 'draft' || $r->steps->isEmpty()) {
                Guard::fail('Only a complete draft route may be published.');
            }
            $previous = null;
            foreach ($r->steps as $s) {
                $in = Item::with('baseUnit')->findOrFail($s->input_item_id);
                $out = Item::with('baseUnit')->findOrFail($s->output_item_id);
                $service = ProcessingService::with('billingUnit')->findOrFail($s->processing_service_id);
                if (! $in->is_active || ! $out->is_active || ! $in->is_stocked || ! $out->is_stocked || ! $in->baseUnit->is_active || ! $out->baseUnit->is_active || ! $service->is_active || ! $service->billingUnit->is_active) {
                    Guard::fail('Every stage needs active stocked items, units and a service.');
                }
                if ($previous && $previous !== $in->id) {
                    Guard::fail('The preceding output must equal the next input.');
                }
                if ($in->base_unit_id !== $out->base_unit_id) {
                    Guard::fail('Fabric stages must use the same physical base unit; cross-dimension manufacturing belongs to Production.');
                }
                if (in_array($service->code, ['dyeing', 'combined-dan-dyeing']) && ($in->baseUnit->code !== 'M' || $service->billingUnit->code !== 'M')) {
                    Guard::fail('Dyeing quantities and billing must be in meters.');
                }
                $previous = $out->id;
            }
            $r->update(['state' => 'published']);
            app(AuditLogger::class)->record('processing_route_versions', $r->id, 'published', null, $r->load('steps')->toArray(), $actor);

            return $r;
        }, 3);
    }
}
