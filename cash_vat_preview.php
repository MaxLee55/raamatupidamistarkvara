<?php

/** Split confirmed input VAT by cumulative payments. The last payment settles the rounding remainder. */
function cashVatPaymentMonths(array $payments, int $grossCents, int $deductibleCents): array
{
    $months = [];
    foreach (cashVatPaymentDates($payments, $grossCents, $deductibleCents) as $date => $amount) {
        $month = substr($date, 0, 7);
        $months[$month] = ($months[$month] ?? 0) + $amount;
    }
    return $months;
}

function cashVatPaymentDates(array $payments, int $grossCents, int $deductibleCents): array
{
    if ($grossCents <= 0 || $deductibleCents <= 0 || $deductibleCents > $grossCents) {
        return [];
    }

    $paid = 0;
    $allocated = 0;
    $dates = [];
    foreach ($payments as $payment) {
        $amount = creditCents((string) $payment['amount']);
        if ($amount <= 0 || $amount > $grossCents - $paid) {
            return [];
        }
        $paid += $amount;
        $newTotal = $paid === $grossCents
            ? $deductibleCents
            : (int) round(($paid / $grossCents) * $deductibleCents);
        $date = $payment['payment_date'];
        $dates[$date] = ($dates[$date] ?? 0) + $newTotal - $allocated;
        $allocated = $newTotal;
    }
    return $dates;
}
