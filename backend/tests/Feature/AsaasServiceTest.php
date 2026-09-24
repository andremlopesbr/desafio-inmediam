<?php

namespace Tests\Feature;

use App\Exceptions\AsaasException;
use App\Models\Customer;
use App\Services\Asaas\AsaasService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;

class AsaasServiceTest extends TestCase
{
    private AsaasService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AsaasService();
    }

    public function test_find_or_create_customer_returns_id_if_exists()
    {
        Http::fake([
            '*/customers*' => Http::response(['data' => [['id' => 'cus_123']]], 200)
        ]);

        $customer = new Customer(['name' => 'John', 'document' => '123']);
        $id = $this->service->findOrCreateCustomer($customer);

        $this->assertEquals('cus_123', $id);
    }

    public function test_find_or_create_customer_creates_if_not_exists()
    {
        Http::fake([
            '*/customers*' => Http::sequence()
                ->push(['data' => []], 200) // First call to GET returns empty
                ->push(['id' => 'cus_456'], 200) // Second call to POST returns created
        ]);

        $customer = new Customer(['name' => 'John', 'document' => '123']);
        $id = $this->service->findOrCreateCustomer($customer);

        $this->assertEquals('cus_456', $id);
    }

    public function test_find_customer_4xx_throws_asaas_exception_and_does_not_create()
    {
        Http::fake([
            '*/customers*' => Http::response(['error' => 'bad request'], 400)
        ]);

        $this->expectException(AsaasException::class);
        $this->expectExceptionMessageMatches('/Falha ao buscar cliente no gateway \(HTTP 400\)\./');

        try {
            $customer = new Customer(['name' => 'John', 'document' => '123']);
            $this->service->findOrCreateCustomer($customer);
        } catch (AsaasException $e) {
            $this->assertEquals(422, $e->getCode());
            Http::assertSentCount(1); // Somente o GET foi feito
            throw $e;
        }
    }

    public function test_find_customer_5xx_throws_asaas_exception_and_does_not_create()
    {
        Http::fake([
            '*/customers*' => Http::response(['error' => 'server error'], 500)
        ]);

        $this->expectException(AsaasException::class);
        $this->expectExceptionMessageMatches('/Serviço de pagamento temporariamente indisponível \(HTTP 500\)\./');

        try {
            $customer = new Customer(['name' => 'John', 'document' => '123']);
            $this->service->findOrCreateCustomer($customer);
        } catch (AsaasException $e) {
            $this->assertEquals(502, $e->getCode());
            Http::assertSentCount(1);
            throw $e;
        }
    }

    public function test_find_customer_timeout_throws_asaas_exception_and_does_not_create()
    {
        Http::fake(function () {
            throw new ConnectionException('Timeout');
        });

        $this->expectException(AsaasException::class);
        $this->expectExceptionMessageMatches('/Falha de conexão com o gateway de pagamento/');

        try {
            $customer = new Customer(['name' => 'John', 'document' => '123']);
            $this->service->findOrCreateCustomer($customer);
        } catch (AsaasException $e) {
            $this->assertEquals(504, $e->getCode());
            throw $e;
        }
    }

    public function test_create_credit_card_charge_returns_id()
    {
        Http::fake([
            '*/payments' => Http::response(['id' => 'pay_123'], 200)
        ]);

        $id = $this->service->createCreditCardCharge('cus_123', 150.00, '2026-10-10');
        $this->assertEquals('pay_123', $id);
    }

    public function test_pay_with_credit_card_returns_correct_array()
    {
        Http::fake([
            '*/payments/*/payWithCreditCard' => Http::response([
                'status' => 'CONFIRMED',
                'creditCard' => [
                    'creditCardNumber' => '1234',
                    'creditCardBrand' => 'MASTERCARD',
                    'creditCardToken' => 'tok_123'
                ]
            ], 200)
        ]);

        $customer = new Customer(['name' => 'John', 'document' => '123']);
        $cardData = [
            'card_holder_name' => 'John',
            'card_number' => '1234123412341234',
            'expiry_date' => '12/26',
            'cvv' => '123'
        ];

        $result = $this->service->payWithCreditCard('pay_123', $customer, $cardData);

        $this->assertEquals('CONFIRMED', $result['status']);
        $this->assertEquals('1234', $result['card_last_four']);
        $this->assertEquals('MASTERCARD', $result['card_brand']);
        $this->assertEquals('tok_123', $result['card_token']);
    }

    public function test_http_4xx_throws_asaas_exception()
    {
        Http::fake([
            '*/payments' => Http::response(['error' => 'bad request'], 400)
        ]);

        $this->expectException(AsaasException::class);
        $this->expectExceptionMessageMatches('/Falha ao registrar cobrança no gateway \(HTTP 400\)\./');

        try {
            $this->service->createCreditCardCharge('cus_123', 150.00, '2026-10-10');
        } catch (AsaasException $e) {
            $this->assertEquals(422, $e->getCode());
            throw $e;
        }
    }

    public function test_http_5xx_throws_asaas_exception()
    {
        Http::fake([
            '*/payments' => Http::response(['error' => 'server error'], 500)
        ]);

        $this->expectException(AsaasException::class);
        $this->expectExceptionMessageMatches('/Serviço de pagamento temporariamente indisponível \(HTTP 500\)\./');

        try {
            $this->service->createCreditCardCharge('cus_123', 150.00, '2026-10-10');
        } catch (AsaasException $e) {
            $this->assertEquals(502, $e->getCode());
            throw $e;
        }
    }

    public function test_timeout_throws_asaas_exception()
    {
        Http::fake(function () {
            throw new ConnectionException('Timeout');
        });

        $this->expectException(AsaasException::class);
        $this->expectExceptionMessageMatches('/Falha de conexão com o gateway de pagamento/');

        try {
            $this->service->createCreditCardCharge('cus_123', 150.00, '2026-10-10');
        } catch (AsaasException $e) {
            $this->assertEquals(504, $e->getCode());
            throw $e;
        }
    }

    public function test_invalid_json_throws_asaas_exception()
    {
        Http::fake([
            '*/payments' => Http::response('NOT JSON', 200)
        ]);

        $this->expectException(AsaasException::class);
        $this->expectExceptionMessageMatches('/formato de resposta inválido/');

        try {
            $this->service->createCreditCardCharge('cus_123', 150.00, '2026-10-10');
        } catch (AsaasException $e) {
            $this->assertEquals(502, $e->getCode());
            throw $e;
        }
    }

    public function test_missing_required_field_throws_asaas_exception()
    {
        Http::fake([
            '*/payments' => Http::response(['status' => 'OK'], 200) // Missing 'id'
        ]);

        $this->expectException(AsaasException::class);
        $this->expectExceptionMessageMatches('/ID não retornado/');

        try {
            $this->service->createCreditCardCharge('cus_123', 150.00, '2026-10-10');
        } catch (AsaasException $e) {
            $this->assertEquals(502, $e->getCode());
            throw $e;
        }
    }

    public function test_exception_message_does_not_contain_sensitive_data()
    {
        Http::fake([
            '*/payments/*/payWithCreditCard' => Http::response(['error' => 'fail'], 400)
        ]);

        $customer = new Customer(['name' => 'John', 'document' => '123']);

        $fakePan = '1234123412341234';
        $fakeCvv = '123';
        $fakeApiKey = config('services.asaas.api_key', 'fake-api-key');

        $cardData = [
            'card_holder_name' => 'John',
            'card_number' => $fakePan,
            'expiry_date' => '12/26',
            'cvv' => $fakeCvv
        ];

        try {
            $this->service->payWithCreditCard('pay_123', $customer, $cardData);
            $this->fail('Should have thrown AsaasException');
        } catch (AsaasException $e) {
            $msg = $e->getMessage();
            $this->assertStringNotContainsString($fakePan, $msg);
            $this->assertStringNotContainsString($fakeCvv, $msg);
            $this->assertStringNotContainsString($fakeApiKey, $msg);
        }
    }
}
