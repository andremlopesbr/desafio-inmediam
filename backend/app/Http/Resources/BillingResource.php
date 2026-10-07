<?php

namespace App\Http\Resources;

use App\DTOs\BillingDto;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingResource extends JsonResource
{
    public function toArray($request): array
    {
        $billing = $this->resource;

        if (! $billing instanceof BillingDto) {
            return $this->resource->toArray();
        }

        return [
            'id' => $billing->id,
            'customer' => [
                'id' => $billing->customerId,
                'name' => $billing->customerName,
                'email' => $billing->customerEmail,
            ],
            'plan' => [
                'id' => $billing->planId,
                'name' => $billing->planName,
                'description' => $billing->planDescription,
            ],
            'amount' => $billing->amount->asDecimal(),
            'due_date' => $billing->dueDate,
            'status' => $billing->status,
            'payments' => $billing->payments,
        ];
    }
}
