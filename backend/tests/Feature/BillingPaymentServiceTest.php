<?php

namespace Tests\Feature;

use App\Exceptions\AsaasException;
use App\Models\Billing;
use App\Models\Customer;
use App\Services\Asaas\AsaasService;
use App\Services\BillingPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private $asaasServiceMock;
    private $billingPaymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asaasServiceMock = $this->createMock(AsaasService::class);
        $this->billingPaymentService = new BillingPaymentService($this->asaasServiceMock);
    }

    public function test_payment_success_persists_data_and_updates_billing_status()
    {
        $customer = Customer::create(['name' => 'John', 'document' => '123', 'email' => 'a@a.com']);
        $billing = Billing::create([
            'customer_id' => $customer->id,
            'amount' => 150.00,
            'due_date' => '2026-10-10',
            'status' => 'pending'
        ]);

        $validatedData = [
            'card_holder_name' => 'John Doe',
            'card_number' => '1234',
            'expiry_date' => '12/26',
            'cvv' => '123'
        ];

        $this->asaasServiceMock->expects($this->once())
            ->method('findOrCreateCustomer')
            ->willReturn('cus_123');

        $this->asaasServiceMock->expects($this->once())
            ->method('createCreditCardCharge')
            ->with('cus_123', 150.00, '2026-10-10')
            ->willReturn('pay_123');

        $this->asaasServiceMock->expects($this->once())
            ->method('payWithCreditCard')
            ->willReturn([
                'status' => 'CONFIRMED',
                'card_last_four' => '1234',
                'card_brand' => 'MASTERCARD',
                'card_token' => 'tok_123'
            ]);

        $payment = $this->billingPaymentService->processCreditCardPayment($billing, $validatedData);

        $this->assertDatabaseHas('credit_cards', [
            'customer_id' => $customer->id,
            'card_holder_name' => 'John Doe',
            'card_last_four' => '1234',
            'card_token' => 'tok_123'
        ]);

        $this->assertDatabaseHas('payments', [
            'billing_id' => $billing->id,
            'amount_paid' => 150.00,
            'status' => 'CONFIRMED'
        ]);

        $this->assertDatabaseHas('billings', [
            'id' => $billing->id,
            'status' => 'paid'
        ]);

        $this->assertEquals('paid', $billing->status);
    }

    public function test_payment_failure_does_not_persist_payment_and_does_not_mark_paid()
    {
        $customer = Customer::create(['name' => 'John', 'document' => '123', 'email' => 'a@a.com']);
        $billing = Billing::create([
            'customer_id' => $customer->id,
            'amount' => 150.00,
            'due_date' => '2026-10-10',
            'status' => 'pending'
        ]);

        $validatedData = [
            'card_holder_name' => 'John Doe',
        ];

        $this->asaasServiceMock->expects($this->once())
            ->method('findOrCreateCustomer')
            ->willReturn('cus_123');

        $this->asaasServiceMock->expects($this->once())
            ->method('createCreditCardCharge')
            ->willReturn('pay_123');

        $this->asaasServiceMock->expects($this->once())
            ->method('payWithCreditCard')
            ->willThrowException(new AsaasException("Falha", 422));

        try {
            $this->billingPaymentService->processCreditCardPayment($billing, $validatedData);
            $this->fail('Exception not thrown');
        } catch (AsaasException $e) {
            $this->assertEquals("Falha", $e->getMessage());
        }

        $this->assertDatabaseMissing('credit_cards', [
            'customer_id' => $customer->id,
        ]);

        $this->assertDatabaseMissing('payments', [
            'billing_id' => $billing->id,
        ]);

        $this->assertDatabaseHas('billings', [
            'id' => $billing->id,
            'status' => 'pending'
        ]);
    }

    public function test_billing_must_be_pending_to_process_payment()
    {
        $billing = Billing::create([
            'customer_id' => Customer::create(['name' => 'John', 'document' => '123'])->id,
            'amount' => 150.00,
            'due_date' => '2026-10-10',
            'status' => 'paid'
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Esta cobrança já foi processada ou não está pendente.');

        $this->billingPaymentService->processCreditCardPayment($billing, []);
    }

    public function test_payment_with_rejected_status_does_not_mark_billing_as_paid()
    {
        $customer = Customer::create(['name' => 'John', 'document' => '123']);
        $billing = Billing::create([
            'customer_id' => $customer->id,
            'amount' => 150.00,
            'due_date' => '2026-10-10',
            'status' => 'pending'
        ]);

        $this->asaasServiceMock->method('findOrCreateCustomer')->willReturn('cus_123');
        $this->asaasServiceMock->method('createCreditCardCharge')->willReturn('pay_123');
        $this->asaasServiceMock->method('payWithCreditCard')->willReturn([
            'status' => 'REJECTED',
            'card_last_four' => '1234',
            'card_brand' => 'MASTERCARD',
            'card_token' => 'tok_123'
        ]);

        $payment = $this->billingPaymentService->processCreditCardPayment($billing, [
            'card_holder_name' => 'John',
        ]);

        $this->assertEquals('REJECTED', $payment->status);
        $this->assertNull($payment->paid_at);

        // Billing remains pending
        $this->assertDatabaseHas('billings', [
            'id' => $billing->id,
            'status' => 'pending'
        ]);
    }
}
