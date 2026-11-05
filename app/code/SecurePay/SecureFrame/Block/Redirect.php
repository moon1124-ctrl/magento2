<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Block;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\OrderRepositoryInterface;
use SecurePay\SecureFrame\Model\RequestBuilder;

class Redirect extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RequestBuilder $requestBuilder,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getActionUrl(): string
    {
        return $this->requestBuilder->actionUrl((int) $this->getOrder()->getStoreId());
    }

    /**
     * @return array<string, string>
     */
    public function getFields(): array
    {
        return $this->requestBuilder->build($this->getOrder());
    }

    private function getOrder(): \Magento\Sales\Model\Order
    {
        $order = $this->orderRepository->get((int) $this->checkoutSession->getLastOrderId());
        if (!$order instanceof \Magento\Sales\Model\Order) {
            throw new \RuntimeException('SecurePay redirect requires a sales order.');
        }

        return $order;
    }
}
