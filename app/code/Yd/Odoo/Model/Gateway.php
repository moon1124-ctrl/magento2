<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Odoo 10 external API: /xmlrpc/2/common authenticate, then /xmlrpc/2/object execute_kw.
 *
 * Only product.product search and read are sent. create, write, and unlink are refused
 * before a request is built, so a dry run cannot change Odoo.
 */
class Gateway
{
    private const READ_METHODS = ['search', 'read'];

    private ?int $userId = null;

    public function __construct(
        private readonly Config $config,
        private readonly XmlRpcClient $client
    ) {
    }

    /**
     * @param array<int, mixed> $arguments
     * @param array<string, scalar> $context
     */
    public function execute(
        string $model,
        string $method,
        array $arguments,
        bool $withContext = false,
        array $context = []
    ): mixed {
        if ($model !== 'product.product' || !in_array($method, self::READ_METHODS, true)) {
            throw new LocalizedException(
                __('Odoo is only searched and read. %1.%2 was not called.', $model, $method)
            );
        }
        $params = [
            $this->config->database(),
            $this->login(),
            $this->config->password(),
            $model,
            $method,
            $arguments,
        ];
        if ($withContext || $context !== []) {
            $params[] = ['context' => array_merge(['lang' => 'en_US'], $context)];
        }

        return $this->dispatch('object', 'execute_kw', $params);
    }

    public function login(): int
    {
        if ($this->userId !== null && $this->userId > 0) {
            return $this->userId;
        }
        $result = $this->dispatch('common', 'authenticate', [
            $this->config->database(),
            $this->config->username(),
            $this->config->password(),
            new \stdClass(),
        ]);
        $userId = is_int($result) || (is_string($result) && ctype_digit($result)) ? (int) $result : 0;
        if ($userId <= 0) {
            throw new LocalizedException(__('Odoo login was rejected.'));
        }
        $this->userId = $userId;

        return $userId;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function dispatch(string $service, string $method, array $params): mixed
    {
        try {
            return $this->client->call($this->config->endpoint($service), $method, $params);
        } catch (LocalizedException $exception) {
            throw $this->withoutSecrets($exception);
        }
    }

    private function withoutSecrets(LocalizedException $exception): LocalizedException
    {
        $message = $exception->getMessage();
        foreach ([$this->config->password(), $this->config->username()] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return new LocalizedException(new Phrase($message));
    }
}
