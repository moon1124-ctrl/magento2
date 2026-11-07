<?php

declare(strict_types=1);

namespace Yd\ShoppingFeed\Controller\Index;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly CollectionFactory $collectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute()
    {
        $raw = $this->rawFactory->create();
        $store = $this->storeManager->getStore();
        if (!$this->scopeConfig->isSetFlag('yd_shopping/feed/enabled', 'store', $store->getId())
            || !$this->tokenMatches((int) $store->getId())
        ) {
            return $raw->setHttpResponseCode(404)->setContents('Not found');
        }
        $currency = $store->getCurrentCurrencyCode();
        $media = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product';
        $collection = $this->collectionFactory->create();
        $collection->setStoreId((int) $store->getId());
        $collection->addStoreFilter((int) $store->getId());
        $collection->addAttributeToSelect(['name', 'price', 'image', 'description', 'ean', 'upc']);
        $collection->addPriceData();
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('visibility', ['in' => [
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_IN_SEARCH,
            Visibility::VISIBILITY_BOTH,
        ]]);
        $collection->setPageSize(2000);
        $items = '';
        foreach ($collection as $product) {
            $sku = (string) $product->getSku();
            $stock = $this->stockRegistry->getStockItem($product->getId());
            $available = $stock->getIsInStock() ? 'in_stock' : 'out_of_stock';
            $image = (string) $product->getImage();
            $imageUrl = ($image !== '' && $image !== 'no_selection') ? $media . $image : '';
            $gtin = (string) ($product->getData('ean') ?: $product->getData('upc'));
            $items .= '<item>'
                . '<g:id>' . $this->xml($sku) . '</g:id>'
                . '<g:title>' . $this->xml((string) $product->getName()) . '</g:title>'
                . '<g:description>' . $this->xml(strip_tags((string) $product->getDescription())) . '</g:description>'
                . '<g:link>' . $this->xml((string) $product->getProductUrl()) . '</g:link>'
                . '<g:image_link>' . $this->xml($imageUrl) . '</g:image_link>'
                . '<g:availability>' . $available . '</g:availability>'
                . '<g:price>' . number_format((float) $product->getFinalPrice(), 2, '.', '') . ' ' . $this->xml((string) $currency) . '</g:price>'
                . '<g:gtin>' . $this->xml($gtin) . '</g:gtin>'
                . '<g:condition>new</g:condition>'
                . '</item>';
        }
        $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel><title>'
            . $this->xml((string) $store->getName())
            . '</title><link>' . $this->xml($store->getBaseUrl()) . '</link>' . $items . '</channel></rss>';

        return $raw->setHeader('Content-Type', 'application/xml', true)->setContents($xml);
    }

    private function tokenMatches(int $storeId): bool
    {
        $stored = (string) $this->scopeConfig->getValue('yd_shopping/feed/token', 'store', $storeId);
        if ($stored === '') {
            return false;
        }
        $plain = $this->encryptor->decrypt($stored);
        $expected = $plain !== '' ? $plain : $stored;
        $given = (string) $this->request->getParam('token');

        return $given !== '' && strlen($given) === strlen($expected) && hash_equals($expected, $given);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1);
    }
}
