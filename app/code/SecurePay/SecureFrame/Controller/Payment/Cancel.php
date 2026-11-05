<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class Cancel implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): ResultInterface
    {
        $orderId = (int) $this->checkoutSession->getLastOrderId();
        if ($orderId > 0) {
            $order = $this->orderRepository->get($orderId);
            if ($order->canCancel()) {
                $order->cancel();
                $order->addCommentToStatusHistory(__('Customer canceled during payment.'));
                $this->orderRepository->save($order);
                $this->checkoutSession->restoreQuote();
            }
        }
        $this->messageManager->addNoticeMessage(__('Payment was canceled.'));

        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
