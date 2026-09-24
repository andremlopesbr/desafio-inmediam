<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\CreditCard;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingControllerSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_billing_does_not_expose_sensitive_card_data()
    {
        $customer = Customer::create(['name' => 'John', 'document' => '123', 'email' => 'a@a.com']);
        $billing = Billing::create([
            'customer_id' => $customer->id,
            'amount' => 150.00,
            'due_date' => '2026-10-10',
            'status' => 'paid'
        ]);

        $creditCard = CreditCard::create([
            'customer_id' => $customer->id,
            'card_holder_name' => 'John Doe',
            'card_last_four' => '1234',
            'card_brand' => 'MASTERCARD',
            'card_token' => 'tok_123456789'
        ]);

        Payment::create([
            'billing_id' => $billing->id,
            'credit_card_id' => $creditCard->id,
            'amount_paid' => 150.00,
            'status' => 'CONFIRMED',
            'paid_at' => now(),
        ]);

        $response = $this->getJson("/api/billing/{$billing->id}");

        $response->assertStatus(200);
        $response->assertJsonMissing(['card_token' => 'tok_123456789']);
        $response->assertJsonMissing(['card_number' => '1234']); // it shouldn't even be there
        $response->assertJsonMissing(['cvv' => '123']);
        
        $response->assertJsonFragment([
            'card_last_four' => '1234',
            'card_brand' => 'MASTERCARD',
        ]);
        
        // Assert token is explicitly not in the JSON string at all
        $this->assertStringNotContainsString('tok_123456789', $response->getContent());
    }
}
