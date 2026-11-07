<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
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

    public function execute(): ResponseInterface|ResultInterface
    {
        $productId = (int) $this->getRequest()->getParam('id');
        try {
            $product = $this->productRepository->getById($productId);
            $barcode = $this->zebraLabel->barcodeFromProduct($product);
            $zpl = $this->zebraLabel->zpl((string) $product->getName(), (string) $product->getSku(), $barcode);
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            $redirect = $this->resultRedirectFactory->create();

            return $productId > 0
                ? $redirect->setPath('catalog/product/edit', ['id' => $productId])
                : $redirect->setPath('catalog/product/index');
        }

        try {
            $this->zebraLabel->send($zpl);
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        $safeSku = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $product->getSku());
        $fileName = ($safeSku === null || $safeSku === '' ? 'label' : $safeSku) . '.zpl';

        return $this->fileFactory->create(
            $fileName,
            ['type' => 'string', 'value' => $zpl],
            DirectoryList::VAR_DIR,
            'text/plain'
        );
    }
}
