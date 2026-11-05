<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;

class RequestBuilder
{
    public const TEST_URL = 'https://test.payment.securepay.com.au/secureframe/invoice';
    public const LIVE_URL = 'https://payment.securepay.com.au/secureframe/invoice';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly UrlInterface $url
    ) {
    }

    public function actionUrl(?int $storeId): string
    {
        $test = $this->scopeConfig->isSetFlag('payment/secureframe/test_mode', ScopeInterface::SCOPE_STORE, $storeId);

        return $test ? self::TEST_URL : self::LIVE_URL;
    }

    /**
     * @return array<string, string>
     */
    public function build(Order $order): array
    {
        $storeId = (int) $order->getStoreId();
        $merchantId = (string) $this->scopeConfig->getValue(
            'payment/secureframe/merchant_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $password = $this->secret($storeId);
        $txnType = (string) $this->scopeConfig->getValue(
            'payment/secureframe/transaction_type',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $orderId = (string) $order->getIncrementId();
        $amount = (string) (int) round(((float) $order->getTotalDue()) * 100);
        $timestamp = gmdate('YmdHis');
        $fingerprint = sha1($merchantId . '|' . $password . '|' . $txnType . '|' . $orderId . '|' . $amount . '|' . $timestamp);
        $cards = (string) $this->scopeConfig->getValue(
            'payment/secureframe/accepted_card_types',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $currencyMode = (string) $this->scopeConfig->getValue(
            'payment/secureframe/currency_accepted',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $currency = $currencyMode === 'M' ? (string) $order->getBaseCurrencyCode() : 'AUD';
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        return [
            'merchant_id' => $merchantId,
            'fp_timestamp' => $timestamp,
            'fingerprint' => $fingerprint,
            'bill_name' => 'transact',
            'txn_type' => $txnType,
            'primary_ref' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'template' => (string) $this->scopeConfig->getValue('payment/secureframe/template', ScopeInterface::SCOPE_STORE, $storeId),
            'confirmation' => 'no',
            'return_url' => $this->url->getUrl('secureframe/payment/response'),
            'callback_url' => $this->url->getUrl('secureframe/payment/response', ['callback' => 'true']),
            'return_url_target' => 'parent',
            'return_url_text' => 'Continue',
            'cancel_url' => $this->url->getUrl('secureframe/payment/cancel'),
            'card_types' => str_replace(',', '|', $cards),
            'page_style_url' => (string) $this->scopeConfig->getValue('payment/secureframe/stylesheet_url', ScopeInterface::SCOPE_STORE, $storeId),
            'billing_country' => $billing ? (string) $billing->getCountryId() : '',
            'delivery_country' => $shipping ? (string) $shipping->getCountryId() : '',
            'email_address' => (string) $order->getCustomerEmail(),
            'meta' => 'cart_name_eq_SecurePay SecureFrame|cart_post_method_eq_' . (string) $order->getShippingDescription()
                . '|cart_billing_address_eq_' . $this->addressMeta($billing)
                . '|cart_delivery_address_eq_' . $this->addressMeta($shipping),
        ];
    }

    public function responseFingerprint(string $merchant, string $password, string $orderId, string $amount, string $timestamp, string $summaryCode): string
    {
        $fingerprintAmount = str_contains($amount, '.')
            ? (string) (int) round((float) $amount * 100)
            : $amount;

        return sha1($merchant . '|' . $password . '|' . $orderId . '|' . $fingerprintAmount . '|' . $timestamp . '|' . $summaryCode);
    }

    public function secret(int $storeId): string
    {
        $stored = (string) $this->scopeConfig->getValue(
            'payment/secureframe/transaction_password',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($stored === '') {
            return '';
        }
        $plain = $this->encryptor->decrypt($stored);

        return $plain !== '' ? $plain : $stored;
    }

    private function addressMeta(?\Magento\Sales\Model\Order\Address $address): string
    {
        if ($address === null) {
            return 'no details available';
        }
        $street = $address->getStreet();

        return trim($address->getFirstname() . ' ' . $address->getLastname())
            . ', ' . (string) $address->getCompany()
            . ', ' . (is_array($street) ? implode(' ', $street) : (string) $street)
            . ' ' . (string) $address->getCity()
            . ', ' . (string) $address->getRegion()
            . ' ' . (string) $address->getPostcode()
            . ' ' . (string) $address->getCountryId();
    }
}
