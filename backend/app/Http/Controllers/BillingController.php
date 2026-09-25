<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Http\Requests\PayBillingRequest;
use App\Services\BillingPaymentService;
use App\Exceptions\AsaasException;
use Illuminate\Http\JsonResponse;

class BillingController
{
    public function show(Billing $billing): JsonResponse
    {
        $billing->load(['customer', 'plan', 'payments.creditCard']);
        return response()->json($billing);
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
