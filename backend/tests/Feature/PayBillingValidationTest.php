<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Carbon\Carbon;

class PayBillingValidationTest extends TestCase
{
    use RefreshDatabase;

    private $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create(['name' => 'Test Plan', 'description' => 'Test', 'price' => 100.00]);
        $customer = Customer::create(['name' => 'John Doe', 'email' => 'john@test.com', 'document' => '12345678909']);

        $this->billing = Billing::create([
            'plan_id' => $plan->id,
            'customer_id' => $customer->id,
            'amount' => 150.00,
            'status' => 'pending',
            'due_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        Carbon::setTestNow('2026-09-15 12:00:00');
    }

    private function validPayload()
    {
        return [
            'card_holder_name' => 'JOHN DOE',
            'card_number' => '1234123412341234',
            'expiry_date' => '12/26',
            'cvv' => '123',
            'amount' => 150.00,
        ];
    }

    private function fakeAsaas()
    {
        Http::fake([
            '*/customers' => Http::response(['id' => 'cus_123'], 200),
            '*/payments' => Http::response(['id' => 'pay_123'], 200),
            '*/payWithCreditCard' => function() {
                return Http::response([
                    'status' => 'CONFIRMED',
                    'creditCard' => [
                        'creditCardNumber' => '1234',
                        'creditCardBrand' => 'MASTERCARD',
                        'creditCardToken' => 'tok_' . uniqid()
                    ]
                ], 200);
            },
        ]);
    }

    public function test_valid_payload_is_accepted()
    {
        $this->fakeAsaas();
        $this->postJson("/api/billing/{$this->billing->id}/pay", $this->validPayload())->assertStatus(200);
    }

    public function test_card_number_is_normalized()
    {
        $this->fakeAsaas();
        $payload = $this->validPayload();
        $payload['card_number'] = '1234-1234 1234-1234';

        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(200);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/payWithCreditCard')) {
                return $request['creditCard']['number'] === '1234123412341234';
            }
            return true;
        });
    }

    public function test_card_number_invalid_length_returns_422()
    {
        Http::fake();
        $payload = $this->validPayload();

        $payload['card_number'] = '123456789012'; // 12
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['card_number']);

        $payload['card_number'] = '12345678901234567890'; // 20
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['card_number']);
    }

    public function test_card_number_with_letters_returns_422()
    {
        Http::fake();
        $payload = $this->validPayload();
        $payload['card_number'] = '1234A23412341234';

        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['card_number']);
    }

    public function test_card_holder_name_validation()
    {
        Http::fake();
        $payload = $this->validPayload();

        unset($payload['card_holder_name']);
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['card_holder_name']);

        $payload['card_holder_name'] = str_repeat('A', 256);
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['card_holder_name']);
    }

    public function test_cvv_3_or_4_digits_is_accepted()
    {
        $this->fakeAsaas();

        $payload = $this->validPayload();
        $payload['cvv'] = '123';
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(200);

        // Reset billing to pending for next assertion (first payment marked it paid)
        Billing::where('id', $this->billing->id)->update(['status' => 'pending']);
        $this->billing->refresh();

        $payload['cvv'] = '1234';
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(200);
    }

    public function test_cvv_invalid_length_returns_422()
    {
        Http::fake();

        $payload = $this->validPayload();
        $payload['cvv'] = '12';
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['cvv']);

        $payload['cvv'] = '12345';
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['cvv']);
    }

    public function test_expiry_date_format_returns_422()
    {
        Http::fake();

        $payload = $this->validPayload();
        $payload['expiry_date'] = '12-26';
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['expiry_date']);

        $payload['expiry_date'] = '12/2026';
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['expiry_date']);
    }

    public function test_invalid_month_returns_422()
    {
        Http::fake();

        $payload = $this->validPayload();
        $payload['expiry_date'] = '13/26';
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['expiry_date']);

        $payload['expiry_date'] = '00/26';
        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['expiry_date']);
    }

    public function test_expired_card_returns_422()
    {
        Http::fake();
        $payload = $this->validPayload();
        $payload['expiry_date'] = '08/26';

        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422)->assertJsonValidationErrors(['expiry_date']);
    }

    public function test_current_month_is_valid()
    {
        $this->fakeAsaas();
        $payload = $this->validPayload();
        $payload['expiry_date'] = '09/26';

        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(200);
    }

    public function test_invalid_request_does_not_call_asaas()
    {
        Http::fake();
        $payload = $this->validPayload();
        unset($payload['card_number']);

        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_manipulated_amount_is_ignored()
    {
        $this->fakeAsaas();
        $payload = $this->validPayload();
        $payload['amount'] = 0.01;

        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/payments')
                && !str_contains($request->url(), '/payWithCreditCard')
                && $request['value'] == 150.00;
        });
    }

    public function test_extra_unvalidated_field_is_not_used()
    {
        $this->fakeAsaas();
        $payload = $this->validPayload();
        $payload['extra_field'] = 'hacker';

        $this->postJson("/api/billing/{$this->billing->id}/pay", $payload)->assertStatus(200);
    }
}
