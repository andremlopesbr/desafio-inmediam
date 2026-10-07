<?php

namespace App\Http\Controllers;

use App\DTOs\BillingDto;
use App\Exceptions\AsaasException;
use App\Http\Requests\PayBillingRequest;
use App\Http\Resources\BillingResource;
use App\Models\Billing;
use App\Services\BillingPaymentService;
use Illuminate\Http\JsonResponse;

class BillingController
{
    public function index(): JsonResponse
    {
        $billings = Billing::query()
            ->with(['customer', 'plan', 'payments.creditCard'])
            ->orderBy('due_date')
            ->get();

        $payload = $billings->map(fn (Billing $billing) => BillingDto::fromModel($billing));

        return response()->json(
            BillingResource::collection($payload)
        );
    }

    public function show(Billing $billing): JsonResponse
    {
        $billing->load(['customer', 'plan', 'payments.creditCard']);

        return response()->json(
            new BillingResource(BillingDto::fromModel($billing))
        );
    }

    public function pay(Billing $billing, PayBillingRequest $request, BillingPaymentService $paymentService): JsonResponse
    {
        try {
            $payment = $paymentService->processCreditCardPayment($billing, $request->validated());
            return response()->json($payment);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (AsaasException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 422);
        }
    }
}
