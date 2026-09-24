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
        return DB::transaction(function () use ($billing, $validatedData) {
            // Recarrega o model com lock pessimista
            $lockedBilling = Billing::where('id', $billing->id)->lockForUpdate()->first();

            if (!$lockedBilling || $lockedBilling->status !== 'pending') {
                throw new \DomainException("Esta cobrança já foi processada ou não está pendente.");
            }

            $asaasCustomerId = $this->asaasService->findOrCreateCustomer($lockedBilling->customer);

            $asaasPaymentId = $this->asaasService->createCreditCardCharge(
                $asaasCustomerId,
                $lockedBilling->amount,
                $lockedBilling->due_date
            );

            $asaasPaymentResult = $this->asaasService->payWithCreditCard(
                $asaasPaymentId,
                $lockedBilling->customer,
                $validatedData
            );

            // Apenas persiste se a chamada acima não lançou exceção
            $creditCard = CreditCard::create([
                'customer_id' => $lockedBilling->customer_id,
                'card_holder_name' => $validatedData['card_holder_name'],
                'card_last_four' => $asaasPaymentResult['card_last_four'],
                'card_brand' => $asaasPaymentResult['card_brand'],
                'card_token' => $asaasPaymentResult['card_token'],
            ]);

            $payment = Payment::create([
                'billing_id' => $lockedBilling->id,
                'credit_card_id' => $creditCard->id,
                'amount_paid' => $lockedBilling->amount,
                'status' => $asaasPaymentResult['status'],
                'paid_at' => $asaasPaymentResult['status'] === 'CONFIRMED' ? now() : null,
            ]);

            if ($asaasPaymentResult['status'] === 'CONFIRMED') {
                $lockedBilling->status = 'paid';
                $lockedBilling->save();
            }

            return $payment;
        });
    }
}
