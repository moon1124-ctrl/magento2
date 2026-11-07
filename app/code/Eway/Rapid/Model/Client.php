<?php

declare(strict_types=1);

namespace Eway\Rapid\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Sales\Model\Order;

class Client
{
    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl
    ) {
    }

    /**
     * @return array{AccessCode: string, SharedPaymentUrl: string}
     */
    public function createSharedPayment(Order $order, string $redirectUrl, string $cancelUrl): array
    {
        $storeId = (int) $order->getStoreId();
        $billing = $order->getBillingAddress();
        $street = $billing ? (array) $billing->getStreet() : [];
        $payload = [
            'Method' => 'ProcessPayment',
            'TransactionType' => 'Purchase',
            'RedirectUrl' => $redirectUrl,
            'CancelUrl' => $cancelUrl,
            'Customer' => [
                'FirstName' => $billing ? (string) $billing->getFirstname() : (string) $order->getCustomerFirstname(),
                'LastName' => $billing ? (string) $billing->getLastname() : (string) $order->getCustomerLastname(),
                'Email' => (string) $order->getCustomerEmail(),
                'Street1' => (string) ($street[0] ?? ''),
                'City' => $billing ? (string) $billing->getCity() : '',
                'State' => $billing ? (string) $billing->getRegion() : '',
                'PostalCode' => $billing ? (string) $billing->getPostcode() : '',
                'Country' => strtolower($billing ? (string) $billing->getCountryId() : 'au'),
            ],
            'Payment' => [
                'TotalAmount' => (int) round(((float) $order->getBaseGrandTotal()) * 100),
                'CurrencyCode' => (string) $order->getBaseCurrencyCode(),
                'InvoiceReference' => (string) $order->getIncrementId(),
                'InvoiceDescription' => 'Order ' . $order->getIncrementId(),
            ],
        ];
        $response = $this->request($storeId, 'POST', 'AccessCodesShared', $payload);
        $accessCode = (string) ($response['AccessCode'] ?? '');
        $url = (string) ($response['SharedPaymentUrl'] ?? '');
        if ($accessCode === '' || $url === '') {
            throw new LocalizedException(__('eWAY did not return a payment page.'));
        }

        return ['AccessCode' => $accessCode, 'SharedPaymentUrl' => $url];
    }

    /**
     * @return array<string, mixed>
     */
    public function queryAccessCode(int $storeId, string $accessCode): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $accessCode)) {
            throw new LocalizedException(__('The eWAY access code is not valid.'));
        }

        return $this->request($storeId, 'GET', 'AccessCode/' . rawurlencode($accessCode));
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(int $storeId, string $method, string $path, ?array $payload = null): array
    {
        $key = $this->config->apiKey($storeId);
        $password = $this->config->apiPassword($storeId);
        if ($key === '' || $password === '') {
            throw new LocalizedException(__('eWAY API credentials are not configured.'));
        }
        $this->curl->setTimeout(30);
        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($key . ':' . $password),
        ]);
        $url = $this->config->endpoint($storeId) . $path;
        if ($method === 'POST') {
            $this->curl->post($url, (string) json_encode($payload));
        } else {
            $this->curl->get($url);
        }
        $status = $this->curl->getStatus();
        $body = json_decode((string) $this->curl->getBody(), true);
        if (!is_array($body)) {
            throw new LocalizedException(__('eWAY returned a response that could not be read.'));
        }
        if ($status < 200 || $status >= 300 || !empty($body['Errors'])) {
            throw new LocalizedException(__('eWAY rejected the request (%1).', (string) ($body['Errors'] ?? $status)));
        }

        return $body;
    }
}
