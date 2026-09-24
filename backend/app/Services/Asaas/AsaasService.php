<?php

namespace App\Services\Asaas;

use App\Exceptions\AsaasException;
use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
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

    private function handleResponse($response, string $operation, string $errorMessage)
    {
        if ($response->failed()) {
            $status = $response->status();
            $code = null;
            $description = null;
            $json = $response->json();
            if (is_array($json) && !empty($json['errors']) && is_array($json['errors'])) {
                $code = $json['errors'][0]['code'] ?? null;
                $description = $json['errors'][0]['description'] ?? null;
            }

            $logContext = [
                'operation' => $operation,
                'status' => $status,
                'code' => $code,
                'description' => $description,
            ];

            if ($response->serverError()) {
                Log::error("Asaas Gateway Error", $logContext);
                throw new AsaasException("Serviço de pagamento temporariamente indisponível (HTTP {$status}).", 502);
            }
            if ($response->clientError()) {
                Log::warning("Asaas Gateway Warning", $logContext);
                $finalMessage = $description ? "{$errorMessage}: {$description} (HTTP {$status})." : "{$errorMessage} (HTTP {$status}).";
                throw new AsaasException($finalMessage, 422);
            }
            Log::error("Asaas Gateway Error - Unhandled", $logContext);
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

            $data = $this->handleResponse($response, 'find_customer', "Falha ao buscar cliente no gateway");

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

            $data = $this->handleResponse($response, 'create_customer', "Falha ao criar cliente no gateway");

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

            $data = $this->handleResponse($response, 'create_credit_card_charge', "Falha ao registrar cobrança no gateway");

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

            $data = $this->handleResponse($response, 'pay_with_credit_card', "Falha ao processar pagamento com cartão no gateway");

            if (empty($data['creditCard']['creditCardNumber']) || empty($data['creditCard']['creditCardBrand']) || empty($data['creditCard']['creditCardToken']) || empty($data['status'])) {
                throw new AsaasException("Falha ao processar pagamento com cartão no gateway: dados do cartão não retornados corretamente.", 502);
            }

            return [
                'status' => $data['status'],
                'card_last_four' => substr($data['creditCard']['creditCardNumber'], -4),
                'card_brand' => $data['creditCard']['creditCardBrand'],
                'card_token' => $data['creditCard']['creditCardToken'],
            ];

        } catch (ConnectionException $e) {
            throw new AsaasException("Falha de conexão com o gateway de pagamento (timeout ou indisponibilidade).", 504);
        }
    }
}
