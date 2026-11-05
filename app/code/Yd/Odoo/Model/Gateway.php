<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Framework\Exception\LocalizedException;

class Gateway
{
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

        return $this->client->call($this->config->endpoint('object'), 'execute_kw', $params);
    }

    public function login(): int
    {
        if ($this->userId !== null && $this->userId > 0) {
            return $this->userId;
        }
        $result = $this->client->call($this->config->endpoint('common'), 'login', [
            $this->config->database(),
            $this->config->username(),
            $this->config->password(),
        ]);
        $userId = (int) $result;
        if ($userId <= 0) {
            throw new LocalizedException(__('Odoo login was rejected.'));
        }
        $this->userId = $userId;

        return $userId;
    }
}
