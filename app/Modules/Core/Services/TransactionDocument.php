<?php

namespace App\Modules\Core\Services;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\ProcessingFabricFlow;
use App\Models\PurchaseInvoiceProcessingMatch;
use App\Models\StockCostLayer;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Modules\FinishedGoods\Services\ActualCosting;
use App\Modules\FinishedGoods\Services\PackingService;
use App\Modules\Inventory\Support\Decimal;
use App\Modules\Processing\Services\ProcessingService;
use App\Modules\Production\Services\ProductionService;
use App\Modules\Purchasing\Services\PurchasingService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/** Read-only document projection shared by printable HTML and actual PDF output. */
class TransactionDocument
{
    public function data(Document $document, string $module): array
    {
        abort_unless(in_array($module, ['purchasing', 'processing', 'production', 'finished-goods'], true), 404);
        Gate::authorize($module.'.view', $document);
        if ($module === 'finished-goods') {
            return $this->packing($document);
        }
        if ($module === 'production') {
            return $this->production($document);
        }
        $service = app($module === 'purchasing' ? PurchasingService::class : ProcessingService::class);
        $kind = $service->kind($document);
        $header = $service->header($document);
        $company = Company::find(1);
        $party = $kind === 'job' ? null : ($module === 'purchasing' || $kind === 'bill' ? $header->supplier->party : $header->vendor->role->party);
        $meta = ['Document date' => $document->document_date->format('d M Y')];
        if ($party) {
            $meta[$module === 'purchasing' ? 'Supplier' : 'Contractor'] = $party->legal_name.' / '.$party->code;
            if ($party->phone) {
                $meta['Contact'] = $party->phone;
            }
        }
        if ($kind === 'bill') {
            $meta['Invoice reference'] = $header->supplier_invoice_number;
            $meta['Invoice date'] = $header->supplier_invoice_date->format('d M Y');
            $meta['Due date'] = $header->due_on->format('d M Y');
        } elseif ($module === 'purchasing') {
            $meta['Warehouse'] = Warehouse::find($header->ship_to_warehouse_id ?? $header->warehouse_id)?->name;
            $meta['Reference'] = $header->supplier_reference ?? $header->supplier_challan_number ?? $header->return_reference;
        } elseif ($kind === 'job') {
            $meta['Route'] = $header->route->route_code.' / version '.$header->route->version_number.' / '.$header->route->name;
            $meta['Planned input'] = $header->planned_input_quantity.' '.$header->lines->first()->inputItem->baseUnit->code;
        } else {
            $meta['Processing job'] = $header->job->document->number;
            $meta['Contractor reference'] = $header->contractor_reference;
        }
        [$columns, $rows, $total] = $module === 'purchasing' ? $this->purchasing($kind, $header) : $this->processing($kind, $header);
        if ($module === 'processing') {
            $flow = ProcessingFabricFlow::with('onwardDocument')->where('document_id', $document->id)->orWhere('onward_document_id', $document->id)->first();
            if ($flow) {
                foreach (['fabric_type' => 'Fabric type', 'color' => 'Color / shade', 'design_reference' => 'Design', 'component_type' => 'Component', 'laat_reference' => 'Laat', 'component_count' => 'Components recorded at receipt'] as $field => $label) {
                    if (! empty($flow->details[$field])) {
                        $meta[$label] = $flow->details[$field];
                    }
                }
                if ($flow->onwardDocument) {
                    $meta['Direct vendor handover'] = $flow->onwardDocument->number.' (no warehouse receipt)';
                }
            }
        }

        return compact('document', 'module', 'kind', 'company', 'meta', 'columns', 'rows', 'total') + [
            'logo' => $this->logo(), 'notes' => $header->notes ?? $header->remarks ?? $header->instructions ?? $header->reason,
            'title' => match ($module.':'.$kind) {
                'purchasing:receipt' => 'Goods Receipt Note', 'purchasing:bill' => 'Supplier Bill', 'processing:dispatch' => 'Fabric Dispatch Challan', 'processing:receipt' => 'Processing Receipt', 'processing:bill' => 'Contractor Bill', default => $document->type->name,
            },
        ];
    }

    private function purchasing(string $kind, mixed $header): array
    {
        $columns = match ($kind) {
            'order' => ['Item / description', 'Quantity', 'Unit', 'PKR / unit', 'Discount PKR', 'Net PKR'],
            'receipt' => ['Item / lot / roll', 'PO reference', 'Accepted', 'Rejected', 'Unit', 'Location'],
            'return' => ['Item / lot / roll', 'GRN reference', 'Returned', 'Unit', 'Reason'],
            'bill' => ['Item / description', 'GRN reference', 'Quantity', 'Unit', 'PKR / unit', 'Discount PKR', 'Net PKR'],
        };
        $rows = [];
        $total = in_array($kind, ['order', 'bill']) ? '0.0000' : null;
        foreach ($header->lines as $line) {
            if ($kind === 'order') {
                $net = bcsub(Decimal::round(bcmul($line->quantity, $line->unit_price, 14)), $line->discount_amount, 4);
                $rows[] = [$line->item->sku."\n".$line->description, $line->quantity, Unit::find($line->unit_id)?->code, $line->unit_price, $line->discount_amount, $net];
                $total = bcadd($total, $net, 4);
            } elseif ($kind === 'receipt') {
                $rows[] = [$this->stockLabel($line->position), $line->orderLine->order->document->number, $line->accepted_quantity, $line->rejected_quantity, Unit::find($line->unit_id)?->code, $line->position->location->code];
            } elseif ($kind === 'return') {
                $rows[] = [$this->stockLabel($line->position), $line->receiptLine->receipt->document->number, $line->base_quantity, $line->position->lot->item->baseUnit->code, $line->reason];
            } else {
                $references = $line->matches->map(fn ($m) => $m->receiptLine->receipt->document->number)->unique()->implode(', ');
                $rows[] = [$line->item->sku."\n".$line->description, $references, $line->base_quantity, $line->item->baseUnit->code, $line->unit_price, $line->discount_amount, $line->net_amount];
                $total = bcadd($total, $line->net_amount, 4);
            }
        }

        return [$columns, $rows, $total];
    }

    private function processing(string $kind, mixed $header): array
    {
        $columns = match ($kind) {
            'job' => ['Stage / service', 'Input / output item', 'Contractor', 'Planned input / output', 'PKR / billing unit'],
            'dispatch' => ['Stage / service', 'Source item / lot / roll', 'Custody location', 'Dispatched', 'Unit'],
            'receipt' => ['Stage / result', 'Dispatch', 'Output item / lot / roll', 'Input closed', 'Returned', 'Normal / abnormal loss', 'Charge PKR'],
            'bill' => ['Description', 'Processing receipt', 'Service quantity', 'Billing unit', 'PKR / unit', 'Net PKR'],
        };
        $rows = [];
        $total = in_array($kind, ['receipt', 'bill']) ? '0.0000' : null;
        foreach ($header->lines as $line) {
            if ($kind === 'job') {
                $rows[] = [$line->line_no.' / '.$line->service->name, $line->inputItem->sku.' / '.$line->outputItem->sku, $line->vendorService->vendor->role->party->legal_name, $line->planned_input_quantity.' / '.$line->planned_output_quantity.' '.$line->inputItem->baseUnit->code, $line->agreed_unit_rate_pkr.' / '.$line->billingUnit->code];
            } elseif ($kind === 'dispatch') {
                $rows[] = [$line->step->line_no.' / '.$line->step->service->name, $this->stockLabel($line->source), $line->contractor->location->code, $line->quantity, $line->source->lot->item->baseUnit->code];
            } elseif ($kind === 'receipt') {
                $unit = $line->step->inputItem->baseUnit->code;
                $rows[] = [$line->step->service->name.' / '.$line->disposition, $line->dispatchLine->dispatch->document->number, $this->stockLabel($line->output), $line->source_quantity.' '.$unit, $line->output_quantity.' '.$line->output->lot->item->baseUnit->code, $line->normal_loss_quantity.' / '.$line->abnormal_loss_quantity.' '.$unit, $line->service_accrual_pkr];
                $total = bcadd($total, $line->service_accrual_pkr, 4);
            } else {
                $matches = PurchaseInvoiceProcessingMatch::with('receiptLine.receipt.document')->where('purchase_invoice_line_id', $line->id)->get();
                $references = $matches->map(fn ($m) => $m->receiptLine->receipt->document->number)->unique()->implode(', ');
                $rows[] = [$line->description, $references, $line->quantity, Unit::find($line->unit_id)?->code, $line->unit_price, $line->net_amount];
                $total = bcadd($total, $line->net_amount, 4);
            }
        }

        return [$columns, $rows, $total];
    }

    private function stockLabel(mixed $position): string
    {
        return $position->lot->item->sku."\nLot: ".$position->lot->lot_number.($position->roll ? "\nRoll: ".$position->roll->roll_number : '');
    }

    private function production(Document $document): array
    {
        $service = app(ProductionService::class);
        $kind = $service->kind($document);
        $header = $service->header($document);
        $batch = $kind === 'batch' ? $header : $header->batch;
        $module = 'production';
        $company = Company::find(1);
        $meta = ['Document date' => $document->document_date->format('d M Y'), 'Finished SKU' => $batch->bom->variant->item->sku, 'Design / color' => $batch->bom->variant->design->name.' / '.$batch->bom->variant->color->name, 'Recipe version' => (string) $batch->bom->version_number, 'Planned output' => $batch->planned_output_quantity.' SUIT'];
        $rows = [];
        $total = null;
        if ($kind === 'batch') {
            $title = 'Manufacturing Batch / Production Plan';
            $columns = ['Component / purpose', 'Item', 'Net required', 'Gross planned', 'Unit'];
            foreach ($batch->lines as $p) {
                $rows[] = [$p->bomLine->component_name, $p->item->sku, $p->planned_net_quantity, $p->planned_gross_quantity, $p->item->baseUnit->code];
            }
            $notes = $batch->instructions;
        } else {
            $title = 'Production Consumption and Output';
            $meta['Batch'] = $batch->document->number;
            $meta['Completion'] = $header->is_final ? 'Final report' : 'Partial report';
            $columns = ['Type / item', 'Lot / source', 'Quantity', 'Unit', 'Cost / details'];
            $notes = $header->report_note;
            foreach ($header->materials as $m) {
                $rows[] = ['Consumed / '.$m->position->lot->item->sku, $m->position->lot->lot_number, $m->quantity, $m->position->lot->item->baseUnit->code, $m->extra_material_reason];
            }
            $total = '0.0000';
            foreach ($header->outputs as $o) {
                $layer = StockCostLayer::where('receipt_stock_movement_id', $o->stock_movement_id)->first();
                $value = $layer ? bcadd((string) $layer->values()->where('document_id', $document->id)->sum('amount_pkr'), '0', 4) : '0.0000';
                $total = bcadd($total, $value, 4);
                $rows[] = [ucfirst($o->output_kind).' / '.$o->position->lot->item->sku, $o->position->lot->lot_number.' / '.$o->position->lot->quality_status, $o->quantity, $o->position->lot->item->baseUnit->code, 'PKR '.$value.' / share '.$o->cost_allocation_fraction];
            }
            foreach ($header->wastage as $w) {
                $rows[] = [ucfirst($w->waste_class).' loss', 'Input '.$w->material->line_no, $w->quantity, $w->material->position->lot->item->baseUnit->code, 'Expense PKR '.$w->expense_amount_pkr.' / '.$w->reason];
            }
            foreach ($header->costs as $c) {
                $rows[] = [$c->cost_category, 'Journal line #'.$c->source_journal_line_id, '', 'PKR', $c->amount_pkr];
            }
        }

        return compact('document', 'module', 'kind', 'company', 'meta', 'columns', 'rows', 'total', 'notes', 'title') + ['logo' => $this->logo()];
    }

    private function packing(Document $document): array
    {
        $header = app(PackingService::class)->header($document);
        $module = 'finished-goods';
        $kind = 'packing';
        $company = Company::find(1);
        $title = 'Packing / Recovery Conversion';
        $meta = ['Document date' => $document->document_date->format('d M Y'), 'Operation' => ucfirst($header->operation), 'Packed SKU' => $header->spec->item->sku, 'Specification version' => (string) $header->spec->version_number];
        $columns = ['Type / item', 'Lot / container', 'Quantity', 'Unit', 'Cost / details'];
        $rows = [];
        $total = null;
        $notes = $header->remarks;
        foreach ($header->materials as $m) {
            $rows[] = ['Consumed / '.$m->position->lot->item->sku, $m->position->lot->lot_number, $m->quantity, $m->position->lot->item->baseUnit->code, ''];
        }
        if ($document->status === 'posted') {
            $total = '0.0000';
        }
        foreach ($header->outputs as $o) {
            $layer = StockCostLayer::where('receipt_stock_movement_id', $o->stock_movement_id)->first();
            $value = $layer ? bcadd((string) $layer->values()->where('document_id', $document->id)->sum('amount_pkr'), '0', 4) : null;
            if ($value !== null) {
                $total = bcadd($total, $value, 4);
            }
            $rows[] = ['Received / '.$o->position->lot->item->sku, $o->position->lot->lot_number.' / '.$o->position->lot->quality_status, $o->quantity, $o->position->lot->item->baseUnit->code, $value === null ? 'Not posted' : 'PKR '.$value];
            foreach ($o->packages as $package) {
                $rows[] = ['Container', $package->package_code, $package->quantity, 'PACK', 'Weight '.($package->gross_weight_kg ?? 'not recorded').' KG'];
            }
        }

        return compact('document', 'module', 'kind', 'company', 'title', 'meta', 'columns', 'rows', 'total', 'notes') + ['logo' => $this->logo()];
    }

    public function costing(Document $document): array
    {
        Gate::authorize('finished-goods.viewAny');
        abort_unless($document->status === 'posted' && in_array($document->type->code, ['production_batch', 'finished_goods_packing'], true), 404);
        $report = app(ActualCosting::class)->report($document);
        $module = 'finished-goods';
        $kind = 'costing';
        $company = Company::find(1);
        $title = 'Actual Batch / Packing Costing';
        $meta = ['Source document' => $document->number, 'Document date' => $document->document_date->format('d M Y'), 'Basis' => 'Actual posted acquisition values; excludes later stock issues', 'Accounted non-rejected suits' => $report['good'], 'Retained rejected suits' => $report['rejected']];
        $columns = ['Output / actual source', 'Lot / evidence', 'Quantity', 'Unit', 'PKR'];
        $rows = [];
        foreach ($report['rows'] as $r) {
            $o = $r['output'];
            $rows[] = ['Output / '.$o->position->lot->item->sku, $o->position->lot->lot_number.' / quality now '.$o->position->lot->quality_status, $o->quantity, $o->position->lot->item->baseUnit->code, $r['cost']];
            $rows[] = ['Per-suit actual cost', 'Equivalent content derived from the specification', $r['suits'], 'SUIT', $r['per_suit'] ?? 'Not applicable'];
        }
        foreach ($report['all'] as $part) {
            $rows[] = [$part['label'], 'Source document #'.$part['document'].' / stock position #'.$part['position'], '', 'PKR', $part['amount']];
        }
        $total = $report['total'];
        $notes = 'Read-only historical acquisition cost. Source components explain the output total; do not add both sections. Rejected outputs retain their own value. Different physical units are never treated as equivalent quantities.';

        return compact('document', 'module', 'kind', 'company', 'title', 'meta', 'columns', 'rows', 'total', 'notes') + ['logo' => $this->logo()];
    }

    public function logo(): ?string
    {
        $path = CompanySetting::where('setting_key', 'company.logo')->first()?->value_json['path'] ?? null;
        // Never load arbitrary local paths or external SVG/URLs from settings.
        if (! is_string($path) || ! preg_match('#^company/branding/[A-Za-z0-9._-]+$#D', $path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $bytes = Storage::disk('local')->get($path);
        if (strlen($bytes) > 2097152) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] > 4096 || $info[1] > 4096 || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }
        if ($info['mime'] !== 'image/jpeg' && ! function_exists('imagecreatefromstring')) {
            return null;
        }
        if ($info['mime'] === 'image/webp') {
            if (! function_exists('imagecreatefromstring')) {
                return null;
            }
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                return null;
            }
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $info['mime'] = 'image/png';
        }

        return 'data:'.$info['mime'].';base64,'.base64_encode($bytes);
    }
}
