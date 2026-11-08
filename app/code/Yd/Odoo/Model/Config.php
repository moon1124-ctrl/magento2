<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;

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

    /**
     * Stock flag on its own. A one-product test can write stock while the connection stays off.
     */
    public function stockSyncRequested(): bool
    {
        return $this->scopeConfig->isSetFlag('yd_odoo/sync/import_stock');
    }

    /**
     * @throws LocalizedException
     */
    public function endpoint(string $service): string
    {
        if ($service !== 'common' && $service !== 'object') {
            throw new LocalizedException(__('Unknown Odoo XML-RPC service.'));
        }
        $parts = parse_url(trim((string) $this->scopeConfig->getValue('yd_odoo/connection/url')));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new LocalizedException(__('Set the Odoo URL to an absolute https:// address.'));
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new LocalizedException(__('The Odoo URL must use HTTPS so the XML-RPC password is encrypted in transit.'));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new LocalizedException(__('Remove the username and password from the Odoo URL.'));
        }
        $host = (string) $parts['host'];
        if (preg_match('/[\s\/\\\\#?]/', $host) === 1) {
            throw new LocalizedException(__('The Odoo URL host is not valid.'));
        }
        $port = $this->portSuffix($parts);
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        $path = preg_replace('#/xmlrpc(/2)?(/(common|object))?$#', '', $path) ?? $path;

        return 'https://' . $host . $port . $path . '/xmlrpc/2/' . $service;
    }

    /**
     * @param array<string, mixed> $parts
     * @throws LocalizedException
     */
    private function portSuffix(array $parts): string
    {
        if (isset($parts['port'])) {
            return $this->explicitPort((int) $parts['port']);
        }
        $configured = (int) $this->scopeConfig->getValue('yd_odoo/connection/port');
        // 8069 is Odoo's cleartext port. Do not attach it to an https:// host.
        if ($configured === 443 || $configured === 8069 || $configured === 0) {
            return '';
        }

        return $this->explicitPort($configured);
    }

    /**
     * @throws LocalizedException
     */
    private function explicitPort(int $port): string
    {
        if ($port < 1 || $port > 65535) {
            throw new LocalizedException(__('The Odoo port is not valid.'));
        }
        if ($port === 443) {
            return '';
        }

        return ':' . $port;
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
