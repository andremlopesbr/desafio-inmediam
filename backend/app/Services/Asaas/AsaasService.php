<?php

namespace App\Services\Asaas;

use App\Exceptions\AsaasException;
use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Exception;

class AsaasService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout = 15;

    public function __construct()
    {
        $this->apiKey = config('services.asaas.api_key', '');
        $this->baseUrl = config('services.asaas.base_url', '');
    }

    private function client()
    {
        return Http::withHeaders(['access_token' => $this->apiKey])->timeout($this->timeout);
    }

    private function handleResponse($response, string $errorMessage)
    {
        if ($response->failed()) {
            if ($response->serverError()) {
                throw new AsaasException("Serviço de pagamento temporariamente indisponível (HTTP {$response->status()}).", 502);
            }
            if ($response->clientError()) {
                throw new AsaasException("{$errorMessage} (HTTP {$response->status()}).", 422);
            }
            throw new AsaasException("Erro inesperado na integração com gateway.", 502);
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new AsaasException("{$errorMessage}: formato de resposta inválido.", 502);
        }

        return $data;
    }

    public function findOrCreateCustomer(Customer $customer): string
    {
        try {
            // Tenta localizar
            $response = $this->client()->get("{$this->baseUrl}/customers", [
                'cpfCnpj' => $customer->document
            ]);

            $data = $this->handleResponse($response, "Falha ao buscar cliente no gateway");

            if (!empty($data['data']) && isset($data['data'][0]['id'])) {
                return (string) $data['data'][0]['id'];
            }

            // Não encontrou, cria
            $response = $this->client()->post("{$this->baseUrl}/customers", [
                'name' => $customer->name,
                'email' => $customer->email,
                'cpfCnpj' => $customer->document,
                'notificationDisabled' => true,
            ]);

            $data = $this->handleResponse($response, "Falha ao criar cliente no gateway");

            if (empty($data['id'])) {
                throw new AsaasException("Falha ao criar cliente no gateway: ID não retornado.", 502);
            }

            return (string) $data['id'];

        } catch (ConnectionException $e) {
            throw new AsaasException("Falha de conexão com o gateway de pagamento (timeout ou indisponibilidade).", 504);
        }
    }

    public function createCreditCardCharge(string $asaasCustomerId, $amount, string $dueDate): string
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/payments", [
                'customer' => $asaasCustomerId,
                'billingType' => 'CREDIT_CARD',
                'value' => $amount,
                'dueDate' => $dueDate,
            ]);

            $data = $this->handleResponse($response, "Falha ao registrar cobrança no gateway");

            if (empty($data['id'])) {
                throw new AsaasException("Falha ao registrar cobrança no gateway: ID não retornado.", 502);
            }

            return (string) $data['id'];

        } catch (ConnectionException $e) {
            throw new AsaasException("Falha de conexão com o gateway de pagamento (timeout ou indisponibilidade).", 504);
        }
    }

    public function payWithCreditCard(string $asaasPaymentId, Customer $customer, array $cardData): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/payments/{$asaasPaymentId}/payWithCreditCard", [
                'creditCard' => [
                    'holderName' => $cardData['card_holder_name'],
                    'number' => $cardData['card_number'],
                    'expiryMonth' => explode('/', $cardData['expiry_date'])[0],
                    'expiryYear' => '20' . explode('/', $cardData['expiry_date'])[1],
                    'ccv' => $cardData['cvv'],
                ],
                'creditCardHolderInfo' => [
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'cpfCnpj' => $customer->document,
                    'phone' => '0000000000',
                    'postalCode' => '00000000',
                    'addressNumber' => '0',
                ],
            ]);

            $data = $this->handleResponse($response, "Falha ao processar pagamento com cartão no gateway");

            if (empty($data['creditCard']['creditCardNumber']) || empty($data['creditCard']['creditCardBrand']) || empty($data['creditCard']['creditCardToken'])) {
                throw new AsaasException("Falha ao processar pagamento com cartão no gateway: dados do cartão não retornados corretamente.", 502);
            }

            return [
                'card_last_four' => $data['creditCard']['creditCardNumber'],
                'card_brand' => $data['creditCard']['creditCardBrand'],
                'card_token' => $data['creditCard']['creditCardToken'],
            ];

        } catch (ConnectionException $e) {
            throw new AsaasException("Falha de conexão com o gateway de pagamento (timeout ou indisponibilidade).", 504);
        }
    }
}
