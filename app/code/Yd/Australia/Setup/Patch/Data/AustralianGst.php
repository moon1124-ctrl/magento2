<?php

declare(strict_types=1);

namespace Yd\Australia\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Tax\Api\Data\TaxRateInterfaceFactory;
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;

class AustralianGst implements DataPatchInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TaxRateInterfaceFactory $rateFactory,
        private readonly TaxRateRepositoryInterface $rateRepository,
        private readonly TaxRuleInterfaceFactory $ruleFactory,
        private readonly TaxRuleRepositoryInterface $ruleRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter
    ) {
    }

    public function apply(): void
    {
        $this->ensureRate();
        $this->storeDefaults();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    private function ensureRate(): void
    {
        $connection = $this->resource->getConnection();
        $existing = $connection->fetchOne(
            'SELECT tax_calculation_rate_id FROM ' . $this->resource->getTableName('tax_calculation_rate') . ' WHERE code = ?',
            ['AU-GST']
        );
        if ($existing) {
            return;
        }
        $productClass = (int) $connection->fetchOne(
            'SELECT class_id FROM ' . $this->resource->getTableName('tax_class') . " WHERE class_name = 'Taxable Goods' AND class_type = 'PRODUCT'"
        );
        $customerClass = (int) $connection->fetchOne(
            'SELECT class_id FROM ' . $this->resource->getTableName('tax_class') . " WHERE class_name = 'Retail Customer' AND class_type = 'CUSTOMER'"
        );
        if ($productClass <= 0 || $customerClass <= 0) {
            return;
        }
        $rate = $this->rateFactory->create();
        $rate->setCode('AU-GST');
        $rate->setTaxCountryId('AU');
        $rate->setTaxRegionId(0);
        $rate->setTaxPostcode('*');
        $rate->setRate(10);
        $saved = $this->rateRepository->save($rate);
        $rule = $this->ruleFactory->create();
        $rule->setCode('AU-GST');
        $rule->setPriority(0);
        $rule->setCalculateSubtotal(0);
        $rule->setCustomerTaxClassIds([$customerClass]);
        $rule->setProductTaxClassIds([$productClass]);
        $rule->setTaxRateIds([(int) $saved->getId()]);
        $this->ruleRepository->save($rule);
    }

    private function storeDefaults(): void
    {
        $country = (string) $this->scopeConfig->getValue('general/country/default');
        if ($country !== '' && $country !== 'US') {
            return;
        }
        $values = [
            'general/country/default' => 'AU',
            'general/locale/timezone' => 'Australia/Sydney',
            'general/locale/code' => 'en_AU',
            'currency/options/base' => 'AUD',
            'currency/options/default' => 'AUD',
            'currency/options/allow' => 'AUD',
            'shipping/origin/country_id' => 'AU',
            'tax/calculation/price_includes_tax' => '1',
            'tax/defaults/country' => 'AU',
            'tax/display/type' => '2',
            'tax/display/shipping' => '2',
            'tax/cart_display/price' => '2',
            'tax/cart_display/subtotal' => '2',
        ];
        foreach ($values as $path => $value) {
            $this->configWriter->save($path, $value);
        }
    }
}
