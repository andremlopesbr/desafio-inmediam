<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\CreditCard;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

    public function pay(string $id, Request $request): JsonResponse
    {
        $billing = Billing::find($id);

        if (!Billing::where('id', $id)->first()) {
            return response()->json(['error' => 'Cobrança não encontrada'], 404);
        }

        $apiKey = config('services.asaas.api_key');
        $baseUrl = config('services.asaas.base_url');

        $customer = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/customers", [
            'name' => $billing->customer->name,
            'email' => $billing->customer->email,
            'cpfCnpj' => $billing->customer->document,
            'notificationDisabled' => true, // ATENÇÃO: Não recomendo modificar, pois o Asaas envia notificações mesmo no ambiente de sandbox.
        ]);

        $customer = (object) $customer->json();

        $charge = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/payments", [
            'customer' => $customer->id,
            'billingType' => 'CREDIT_CARD',
            'value' => $request->amount,
            'dueDate' => $billing->due_date,
        ]);

        $charge = (object) $charge->json();

        $response = Http::withHeaders(['access_token' => $apiKey])->post("$baseUrl/payments/{$charge->id}/payWithCreditCard", [
            'creditCard' => [
                'holderName' => $request->card_holder_name,
                'number' => $request->card_number,
                'expiryMonth' => explode('/', $request->expiry_date)[0],
                'expiryYear' => '20' . explode('/', $request->expiry_date)[1],
                'ccv' => $request->cvv,
            ],
            'creditCardHolderInfo' => [
                'name' => $billing->customer->name,
                'email' => $billing->customer->email,
                'cpfCnpj' => $billing->customer->document,
                'phone' => '0000000000',
                'postalCode' => '00000000',
                'addressNumber' => '0',
            ],
        ]);

        $response = (object) $response->json();

        $credit_card = CreditCard::create([
            'customer_id' => $billing->customer_id,
            'card_holder_name' => $request->card_holder_name,
            'card_last_four' => $response->creditCard['creditCardNumber'],
            'card_brand' => $response->creditCard['creditCardBrand'],
            'card_token' => $response->creditCard['creditCardToken'],
        ]);

        $payment = Payment::create([
            'billing_id' => $billing->id,
            'credit_card_id' => $credit_card->id,
            'amount_paid' => $request->amount,
            'status' => $response->status,
            'paid_at' => now(),
        ]);

        $billing->status = 'paid';
        $billing->save();

        return response()->json($payment);
    }
}
