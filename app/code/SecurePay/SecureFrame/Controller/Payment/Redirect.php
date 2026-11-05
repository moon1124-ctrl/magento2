<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use SecurePay\SecureFrame\Model\SecureFrame;

class Redirect implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function execute(): ResultInterface
    {
        $orderId = (int) $this->checkoutSession->getLastOrderId();
        if ($orderId <= 0) {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        $order = $this->orderRepository->get($orderId);
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== SecureFrame::CODE || $order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        return $this->pageFactory->create();
    }
}
