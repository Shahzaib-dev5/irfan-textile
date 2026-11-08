<?php

namespace App\Modules\Accounting\Support;

class FinanceVoucherLabel
{
    public static function forCode(?string $code, ?string $fallback = null): string
    {
        return match ($code) {
            'finance_payment' => 'Cash payable voucher',
            'finance_receipt' => 'Cash receipt voucher',
            default => $fallback ?? 'Finance voucher',
        };
    }
}
