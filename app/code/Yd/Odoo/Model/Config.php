<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('yd_odoo/connection/enabled');
    }

    public function importProducts(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag('yd_odoo/sync/import_products');
    }

    public function importStock(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag('yd_odoo/sync/import_stock');
    }

    public function endpoint(string $service): string
    {
        $url = rtrim((string) $this->scopeConfig->getValue('yd_odoo/connection/url'), '/');
        $port = (int) $this->scopeConfig->getValue('yd_odoo/connection/port');
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url . '/xmlrpc/' . $service;
        }
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'];
        $portPart = isset($parts['port']) ? '' : ($port > 0 ? ':' . $port : '');

        return $scheme . '://' . $host . $portPart . '/xmlrpc/' . $service;
    }

    public function database(): string
    {
        return (string) $this->scopeConfig->getValue('yd_odoo/connection/database');
    }

    public function username(): string
    {
        return (string) $this->scopeConfig->getValue('yd_odoo/connection/username');
    }

    public function password(): string
    {
        $stored = (string) $this->scopeConfig->getValue('yd_odoo/connection/password');
        if ($stored === '') {
            return '';
        }
        $plain = $this->encryptor->decrypt($stored);

        return $plain !== '' ? $plain : $stored;
    }
}
