<?php

declare(strict_types=1);

namespace Magento\Eav\Setup;

/**
 * Present only when Magento has not generated EavSetupFactory yet.
 * Production uses the generated factory; unit tests mock this stand-in.
 */
class EavSetupFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): EavSetup
    {
        throw new \BadMethodCallException('Mock EavSetupFactory::create() in the unit test.');
    }
}
