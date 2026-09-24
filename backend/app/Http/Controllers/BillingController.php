<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\CreditCard;
use App\Models\Payment;
use App\Http\Requests\PayBillingRequest;
use Illuminate\Http\JsonResponse;
use App\Services\Asaas\AsaasService;
use App\Exceptions\AsaasException;

class BillingController
{
    public function show(string $id): JsonResponse
    {
        $billing = Billing::with(['plan', 'payments.creditCard'])->find($id);

        if (!$billing) {
            return response()->json(['error' => 'Cobrança não encontrada'], 404);
        }

        return response()->json($billing);
    }

    public function pay(string $id, PayBillingRequest $request, AsaasService $asaasService): JsonResponse
    {
        $billing = Billing::find($id);

        if (!Billing::where('id', $id)->first()) {
            return response()->json(['error' => 'Cobrança não encontrada'], 404);
        }

        $validated = $request->validated();

        try {
            $asaasCustomerId = $asaasService->findOrCreateCustomer($billing->customer);

            $asaasPaymentId = $asaasService->createCreditCardCharge(
                $asaasCustomerId,
                $billing->amount,
                $billing->due_date
            );

            $asaasPaymentResult = $asaasService->payWithCreditCard(
                $asaasPaymentId,
                $billing->customer,
                $validated
            );

        } catch (AsaasException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 422);
        }

        $credit_card = CreditCard::create([
            'customer_id' => $billing->customer_id,
            'card_holder_name' => $validated['card_holder_name'],
            'card_last_four' => $asaasPaymentResult['card_last_four'],
            'card_brand' => $asaasPaymentResult['card_brand'],
            'card_token' => $asaasPaymentResult['card_token'],
        ]);

        $payment = Payment::create([
            'billing_id' => $billing->id,
            'credit_card_id' => $credit_card->id,
            'amount_paid' => $billing->amount,
            'status' => $response->status,
            'paid_at' => now(),
        ]);

        $billing->status = 'paid';
        $billing->save();

        return response()->json($payment);
    }
}
