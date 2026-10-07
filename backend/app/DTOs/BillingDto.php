<?php

namespace App\DTOs;

use App\Models\Billing;
use App\ValueObjects\Money;

final class BillingDto
{
    public function __construct(
        public readonly int $id,
        public readonly int $customerId,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly int $planId,
        public readonly string $planName,
        public readonly string $planDescription,
        public readonly Money $amount,
        public readonly string $dueDate,
        public readonly string $status,
        public readonly array $payments = [],
    ) {}

    public static function fromModel(Billing $billing): self
    {
        $plan = $billing->plan;
        $customer = $billing->customer;
        $dueDate = $billing->due_date;

        if ($dueDate instanceof \DateTimeInterface) {
            $dueDate = $dueDate->format('Y-m-d');
        } elseif (is_string($dueDate) && $dueDate !== '') {
            $dueDate = \Carbon\Carbon::parse($dueDate)->format('Y-m-d');
        } else {
            $dueDate = '';
        }

        return new self(
            id: $billing->id,
            customerId: (int) $customer?->id,
            customerName: $customer?->name ?? 'Cliente',
            customerEmail: $customer?->email ?? '',
            planId: (int) $plan?->id,
            planName: $plan?->name ?? 'Plano',
            planDescription: $plan?->description ?? '',
            amount: Money::fromDecimal((float) $billing->amount),
            dueDate: $dueDate,
            status: $billing->status ?? 'pending',
            payments: $billing->payments?->toArray() ?? [],
        );
    }
}
