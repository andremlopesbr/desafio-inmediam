<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\CreditCard;
use App\Models\Payment;
use App\Services\Asaas\AsaasService;
use Exception;
use Illuminate\Support\Facades\DB;

class BillingPaymentService
{
    public function __construct(
        private AsaasService $asaasService
    ) {}

    public function processCreditCardPayment(Billing $billing, array $validatedData): Payment
    {
        if ($billing->status !== 'pending') {
            throw new Exception("Esta cobrança já foi processada ou não está pendente.");
        }

        $asaasCustomerId = $this->asaasService->findOrCreateCustomer($billing->customer);

        $asaasPaymentId = $this->asaasService->createCreditCardCharge(
            $asaasCustomerId,
            $billing->amount,
            $billing->due_date
        );

        $asaasPaymentResult = $this->asaasService->payWithCreditCard(
            $asaasPaymentId,
            $billing->customer,
            $validatedData
        );

        // Apenas persiste se a chamada acima não lançou exceção
        $creditCard = CreditCard::create([
            'customer_id' => $billing->customer_id,
            'card_holder_name' => $validatedData['card_holder_name'],
            'card_last_four' => $asaasPaymentResult['card_last_four'],
            'card_brand' => $asaasPaymentResult['card_brand'],
            'card_token' => $asaasPaymentResult['card_token'],
        ]);

        $payment = Payment::create([
            'billing_id' => $billing->id,
            'credit_card_id' => $creditCard->id,
            'amount_paid' => $billing->amount,
            'status' => $asaasPaymentResult['status'],
            'paid_at' => $asaasPaymentResult['status'] === 'CONFIRMED' ? now() : null,
        ]);

        if ($asaasPaymentResult['status'] === 'CONFIRMED') {
            $billing->status = 'paid';
            $billing->save();
        }

        return $payment;
    }
}
