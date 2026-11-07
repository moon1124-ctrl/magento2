<?php

declare(strict_types=1);

namespace Eway\Rapid\Controller\Payment;

use Eway\Rapid\Model\Client;
use Eway\Rapid\Model\Eway;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class Start implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Client $client,
        private readonly UrlInterface $url,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $orderId = (int) $this->checkoutSession->getLastOrderId();
        if ($orderId <= 0) {
            return $redirect->setPath('checkout/cart');
        }
        try {
            $order = $this->orderRepository->get($orderId);
            $payment = $order->getPayment();
            if (!$payment || $payment->getMethod() !== Eway::CODE || $order->getState() !== Order::STATE_PENDING_PAYMENT) {
                return $redirect->setPath('checkout/cart');
            }
            $shared = $this->client->createSharedPayment(
                $order,
                $this->url->getUrl('eway/payment/result'),
                $this->url->getUrl('eway/payment/cancel')
            );
            $payment->setAdditionalInformation('eway_access_code', $shared['AccessCode']);
            $this->orderRepository->save($order);

            return $redirect->setUrl($shared['SharedPaymentUrl']);
        } catch (\Throwable $exception) {
            $this->messageManager->addErrorMessage(__('Payment could not be started. %1', $exception->getMessage()));

            return $redirect->setPath('checkout/cart');
        }
    }
}
