<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Billing $pendingBilling;
    private Billing $paidBilling;
    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'Test Plan', 'price' => 79.90, 'description' => 'Test']);
        $customer = Customer::create(['name' => 'John', 'email' => 'john@test.com', 'document' => '12345678901']);

        $this->pendingBilling = Billing::create([
            'plan_id' => $plan->id,
            'customer_id' => $customer->id,
            'amount' => 79.90,
            'status' => 'pending',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->paidBilling = Billing::create([
            'plan_id' => $plan->id,
            'customer_id' => $customer->id,
            'amount' => 79.90,
            'status' => 'paid',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->validPayload = [
            'card_holder_name' => 'John Doe',
            'card_number' => '1234123412341234',
            'expiry_date' => '12/30',
            'cvv' => '123',
        ];
    }

    public function test_inexistent_billing_returns_404()
    {
        $response = $this->postJson('/api/billing/9999/pay', $this->validPayload);
        $response->assertStatus(404);
    }

    public function test_invalid_payload_returns_422()
    {
        $response = $this->postJson("/api/billing/{$this->pendingBilling->id}/pay", []);
        $response->assertStatus(422);
    }

    public function test_billing_already_paid_does_not_call_gateway()
    {
        Http::fake(); // Fake without defining URLs means any call throws error, or we can assert no calls.
        $response = $this->postJson("/api/billing/{$this->paidBilling->id}/pay", $this->validPayload);
        
        $response->assertStatus(409);
        $response->assertJson(['error' => 'Esta cobrança já foi processada ou não está pendente.']);
        Http::assertNothingSent();
    }

    public function test_amount_manipulated_is_ignored_and_gateway_returns_confirmed()
    {
        Http::fake([
            '*/customers*' => Http::response(['data' => [['id' => 'cus_123', 'deleted' => false]]]),
            '*/payments' => Http::response(['id' => 'pay_123']),
            '*/payWithCreditCard' => Http::response([
                'status' => 'CONFIRMED',
                'creditCard' => [
                    'creditCardNumber' => '4444',
                    'creditCardBrand' => 'MASTERCARD',
                    'creditCardToken' => 'tok_123',
                ]
            ]),
        ]);

        $payload = $this->validPayload;
        $payload['amount'] = 0.01; // Manipulated amount

        $response = $this->postJson("/api/billing/{$this->pendingBilling->id}/pay", $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('billings', ['id' => $this->pendingBilling->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['amount_paid' => 79.90, 'status' => 'CONFIRMED']); // Should use 79.90, not 0.01
        
        // Assert json response does not contain sensitive data
        $response->assertJsonMissing(['card_token' => 'tok_123', 'cvv' => '123']);
        // Check for undefined fields or raw Asaas errors not leaking
    }

    public function test_gateway_rejected_keeps_billing_pending()
    {
        Http::fake([
            '*/customers*' => Http::response(['data' => [['id' => 'cus_123', 'deleted' => false]]]),
            '*/payments' => Http::response(['id' => 'pay_123']),
            '*/payWithCreditCard' => Http::response([
                'status' => 'REJECTED',
                'creditCard' => [
                    'creditCardNumber' => '4444',
                    'creditCardBrand' => 'MASTERCARD',
                    'creditCardToken' => 'tok_123',
                ]
            ]),
        ]);

        $response = $this->postJson("/api/billing/{$this->pendingBilling->id}/pay", $this->validPayload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('billings', ['id' => $this->pendingBilling->id, 'status' => 'pending']);
        $this->assertDatabaseHas('payments', ['status' => 'REJECTED']);
    }

    public function test_gateway_timeout_returns_controlled_response()
    {
        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Timeout');
            }
        ]);

        $response = $this->postJson("/api/billing/{$this->pendingBilling->id}/pay", $this->validPayload);

        $response->assertStatus(504);
        $response->assertJson(['error' => 'Falha de conexão com o gateway de pagamento (timeout ou indisponibilidade).']);
    }
}
