<?php

namespace App\Modules\Inventory\Services;

use App\Models\Document;
use App\Modules\Inventory\Services\InventoryGuard as Guard;
use App\Modules\Inventory\Support\Decimal as D;

class StockCostDistribution
{
    public function __construct(private StockLedger $stock) {}

    public function distribute(Document $doc, mixed $allocation, string $value, string $lost, array &$outputs, ?int $lossAccount = null): void
    {
        if (bccomp($value, '0', 4) === 0) {
            $this->stock->link($doc, $allocation, '0', reset($outputs)['layer']->id, null);

            return;
        }
        $chunks = [];
        if (bccomp($lost, '0', 4) > 0) {
            $chunks[] = [null, $lossAccount, $lost];
        }
        $remaining = bcsub($value, $lost, 4);
        $pool = array_reduce($outputs, fn ($sum, $o) => bcadd($sum, $o['amount'], 4), '0');
        foreach ($outputs as $id => &$out) {
            $column = $out['amount'];
            if (bccomp($column, '0', 4) <= 0) {
                continue;
            }
            $amount = bccomp($pool, '0', 4) > 0 ? D::round(bcdiv(bcmul($remaining, $column, 16), $pool, 12)) : '0.0000';
            $lower = bcsub($remaining, bcsub($pool, $column, 4), 4);
            if (bccomp($lower, '0', 4) < 0) {
                $lower = '0.0000';
            }
            if (bccomp($amount, $lower, 4) < 0) {
                $amount = $lower;
            }
            if (bccomp($amount, $column, 4) > 0) {
                $amount = $column;
            }
            if (bccomp($amount, $remaining, 4) > 0) {
                $amount = $remaining;
            }
            $out['amount'] = bcsub($column, $amount, 4);
            $remaining = bcsub($remaining, $amount, 4);
            $pool = bcsub($pool, $column, 4);
            if (bccomp($amount, '0', 4) > 0) {
                $chunks[] = [$out['layer']->id, null, $amount];
            }
        }
        unset($out);
        if (bccomp($remaining, '0', 4)) {
            Guard::fail('Source cost cannot be reconciled to output values.');
        }
        $left = '1.0000000000';
        foreach ($chunks as $i => [$target,$expense,$amount]) {
            $fraction = $i === array_key_last($chunks) ? $left : bcdiv($amount, $value, 10);
            if (bccomp($fraction, '0', 10) <= 0) {
                Guard::fail('This cost split is below supported lineage precision.');
            }
            $this->stock->link($doc, $allocation, $amount, $target, $expense, $fraction);
            $left = bcsub($left, $fraction, 10);
        }
    }
}
