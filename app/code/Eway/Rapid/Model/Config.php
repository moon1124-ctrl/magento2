<?php

declare(strict_types=1);

namespace Eway\Rapid\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isSandbox(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag('payment/eway/sandbox', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function endpoint(?int $storeId = null): string
    {
        return $this->isSandbox($storeId)
            ? 'https://api.sandbox.ewaypayments.com/'
            : 'https://api.ewaypayments.com/';
    }

    public function apiKey(?int $storeId = null): string
    {
        return $this->secret('payment/eway/api_key', $storeId);
    }

    public function apiPassword(?int $storeId = null): string
    {
        return $this->secret('payment/eway/api_password', $storeId);
    }

    private function secret(string $path, ?int $storeId): string
    {
        $stored = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($stored === '') {
            return '';
        }
        $plain = $this->encryptor->decrypt($stored);

        return $plain !== '' ? $plain : $stored;
    }
}
