<?php

namespace App\Modules\Core\Support;

class DocumentPresentation
{
    public static function status(string $status): string
    {
        return match ($status) {
            'draft' => 'Saved for later', 'submitted' => 'Awaiting confirmation', 'posted' => 'Confirmed', default => ucfirst($status)
        };
    }

    public static function label(string $module, string $kind): string
    {
        return 'Confirm '.match ($module.':'.$kind) {
            'finished-goods:packing' => 'packing conversion',
            'production:batch' => 'production batch','production:report' => 'production report',
            'purchasing:order' => 'purchase order', 'purchasing:receipt' => 'goods receipt', 'purchasing:return' => 'purchase return',
            'processing:job' => 'processing job', 'processing:dispatch' => 'dispatch', 'processing:receipt' => 'processing receipt',
            default => 'bill',
        };
    }

    public static function effect(string $module, string $kind): string
    {
        return match ($module.':'.$kind) {
            'finished-goods:packing' => 'Consumes the actual contents once and receives new packed/recovered lots at their real FIFO cost. Containers are trace records, not extra stock.',
            'production:batch' => 'Freezes this design/color, BOM and material plan. Actual stock is consumed only when you confirm a production report.',
            'production:report' => 'Consumes actual materials and receives the measured output in one transaction. Wastage is part of that consumption. Final completion closes the batch.',
            'purchasing:order' => 'Confirms the order so you can receive goods against it. No stock or supplier payable is created.',
            'purchasing:receipt' => 'Adds only accepted goods to warehouse stock. Review inspection quality before confirming.',
            'purchasing:return' => 'Removes the returned goods from your warehouse. Existing supplier bills are not reduced by this physical return.',
            'processing:job' => 'Freezes the route, assigned vendors and agreed rates. You can dispatch fabric next; no fabric moves yet.',
            'processing:dispatch' => 'Moves the selected fabric from your warehouse into the assigned vendor custody. The fabric still belongs to your company.',
            'processing:receipt' => 'Reconciles the issued quantity with returned fabric and measured losses, receives output stock and accrues accepted service charges.',
            default => 'Records the amount owed against the matched receipt. It does not receive stock or record a payment.',
        };
    }
}
