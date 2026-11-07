<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Magento 1.9 did not ship UPC or EAN. The shop add-on used optional, user-defined,
 * global text attributes and copied a 13-digit SKU only into an empty value.
 * Product Details is the Magento 2 group that replaced Magento 1's General group.
 */
class ProductAttributeInstaller
{
    private const GROUP_CODES = ['product-details', 'general'];

    public function install(EavSetup $eavSetup): void
    {
        foreach ($this->definitions() as $code => $definition) {
            $attributeId = (int) $eavSetup->getAttributeId(Product::ENTITY, $code);
            if ($attributeId < 1) {
                $eavSetup->addAttribute(Product::ENTITY, $code, $definition);
                $attributeId = (int) $eavSetup->getAttributeId(Product::ENTITY, $code);
            }
            if ($attributeId < 1) {
                continue;
            }
            $this->linkMissingSets($eavSetup, $attributeId);
        }
        $eavSetup->cleanCache();
    }

    /**
     * Attribute rows only. There is no default, so no product value is written.
     * There is no group key, so Magento does not move an attribute that is already on a set.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            'upc' => $this->definition('UPC', 'UPC-A. An existing product value is left unchanged.'),
            'ean' => $this->definition('EAN', 'EAN-13 or UPC-A. An existing product value is left unchanged.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $label, string $note): array
    {
        return [
            'type' => 'varchar',
            'label' => $label,
            'input' => 'text',
            'required' => false,
            'user_defined' => true,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible' => true,
            'searchable' => false,
            'filterable' => false,
            'comparable' => false,
            'visible_on_front' => false,
            'visible_in_advanced_search' => false,
            'used_in_product_listing' => false,
            'unique' => false,
            'apply_to' => '',
            'note' => $note,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => true,
            'is_filterable_in_grid' => false,
        ];
    }

    private function linkMissingSets(EavSetup $eavSetup, int $attributeId): void
    {
        $setup = $eavSetup->getSetup();
        $connection = $setup->getConnection();
        $entityTypeId = (int) $eavSetup->getEntityTypeId(Product::ENTITY);
        $setTable = $connection->quoteIdentifier($setup->getTable('eav_attribute_set'));
        $groupTable = $connection->quoteIdentifier($setup->getTable('eav_attribute_group'));
        $linkTable = $setup->getTable('eav_entity_attribute');
        $linkSql = $connection->quoteIdentifier($linkTable);
        $setIds = $connection->fetchCol(
            'SELECT attribute_set_id FROM ' . $setTable . ' WHERE entity_type_id = ?',
            [$entityTypeId]
        );

        foreach ($setIds as $setId) {
            $setId = (int) $setId;
            $existing = (int) $connection->fetchOne(
                'SELECT entity_attribute_id FROM ' . $linkSql
                . ' WHERE attribute_set_id = ? AND attribute_id = ?',
                [$setId, $attributeId]
            );
            if ($existing > 0) {
                continue;
            }
            $groupId = $this->groupId($connection, $groupTable, $setId);
            if ($groupId < 1) {
                continue;
            }
            $sortOrder = (int) $connection->fetchOne(
                'SELECT MAX(sort_order) FROM ' . $linkSql . ' WHERE attribute_group_id = ?',
                [$groupId]
            );
            $connection->insert($linkTable, [
                'entity_type_id' => $entityTypeId,
                'attribute_set_id' => $setId,
                'attribute_group_id' => $groupId,
                'attribute_id' => $attributeId,
                'sort_order' => $sortOrder + 1,
            ]);
        }
    }

    private function groupId(AdapterInterface $connection, string $groupTable, int $setId): int
    {
        foreach (self::GROUP_CODES as $code) {
            $groupId = (int) $connection->fetchOne(
                'SELECT attribute_group_id FROM ' . $groupTable
                . ' WHERE attribute_set_id = ? AND attribute_group_code = ?',
                [$setId, $code]
            );
            if ($groupId > 0) {
                return $groupId;
            }
        }

        return (int) $connection->fetchOne(
            'SELECT attribute_group_id FROM ' . $groupTable
            . ' WHERE attribute_set_id = ? ORDER BY default_id DESC, sort_order ASC LIMIT 1',
            [$setId]
        );
    }
}
