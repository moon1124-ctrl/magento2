<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Octocub\UpcCopy\Model\ZebraLabel;

class PrintLabel extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::products';

    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ZebraLabel $zebraLabel,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResponseInterface
    {
        $product = $this->productRepository->getById((int) $this->getRequest()->getParam('id'));
        $barcode = (string) ($product->getData('ean') ?: $product->getData('upc') ?: $product->getSku());
        $zpl = $this->zebraLabel->zpl((string) $product->getName(), (string) $product->getSku(), $barcode);
        $this->zebraLabel->send($zpl);

        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $product->getSku()) . '.zpl';

        return $this->fileFactory->create(
            $fileName,
            ['type' => 'string', 'value' => $zpl],
            \Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR,
            'text/plain'
        );
    }
}
