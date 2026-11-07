<?php

declare(strict_types=1);

namespace Eway\Rapid\Controller\Payment;

use Eway\Rapid\Model\Client;
use Eway\Rapid\Model\Eway;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class Result implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Client $client,
        private readonly OrderSender $orderSender,
        private readonly ResourceConnection $resource,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $accessCode = (string) $this->request->getParam('AccessCode');
        $order = $this->findOrder($accessCode);
        if (!$order) {
            $this->messageManager->addErrorMessage(__('The payment result could not be matched to an order.'));

            return $redirect->setPath('checkout/onepage/failure');
        }
        try {
            $result = $this->client->queryAccessCode((int) $order->getStoreId(), $accessCode);
        } catch (\Throwable $exception) {
            $this->messageManager->addErrorMessage(__('eWAY could not confirm the payment. %1', $exception->getMessage()));

            return $redirect->setPath('checkout/onepage/failure');
        }
        if (empty($result['TransactionStatus'])) {
            if ($order->canCancel()) {
                $order->cancel();
                $order->addCommentToStatusHistory(__('eWAY did not approve the payment.'));
                $this->orderRepository->save($order);
                $this->remember($order);
                $this->checkoutSession->restoreQuote();
            }
            $this->messageManager->addErrorMessage(__('Your card payment was not approved.'));

            return $redirect->setPath('checkout/onepage/failure');
        }
        $this->capture($order, $result);
        $this->remember($order);

        return $redirect->setPath('checkout/onepage/success');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function capture(Order $order, array $result): void
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return;
        }
        $transactionId = (string) ($result['TransactionID'] ?? '');
        if ($transactionId !== '' && (string) $payment->getLastTransId() === $transactionId) {
            return;
        }
        $cents = (int) ($result['TotalAmount'] ?? 0);
        $payment->setTransactionId($transactionId !== '' ? $transactionId : (string) $order->getIncrementId());
        $payment->setIsTransactionClosed(false);
        $payment->registerCaptureNotification($cents / 100);
        $order->addCommentToStatusHistory(__('eWAY approved the payment. Transaction %1.', $transactionId));
        if (!$order->getEmailSent()) {
            $this->orderSender->send($order);
        }
        $this->orderRepository->save($order);
    }

    private function findOrder(string $accessCode): ?Order
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $accessCode)) {
            return null;
        }
        $sessionId = (int) $this->checkoutSession->getLastOrderId();
        if ($sessionId > 0) {
            $order = $this->orderRepository->get($sessionId);
            $stored = (string) $order->getPayment()?->getAdditionalInformation('eway_access_code');
            if ($stored !== '' && hash_equals($stored, $accessCode)) {
                return $order;
            }
        }
        $connection = $this->resource->getConnection();
        $orderId = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('sales_order_payment'), ['parent_id'])
                ->where('method = ?', Eway::CODE)
                ->where('additional_information LIKE ?', '%"eway_access_code":"' . $accessCode . '"%')
                ->limit(1)
        );
        if (!$orderId) {
            return null;
        }
        $order = $this->orderRepository->get((int) $orderId);

        return $order instanceof Order ? $order : null;
    }

    private function remember(Order $order): void
    {
        $this->checkoutSession->setLastOrderId((int) $order->getEntityId());
        $this->checkoutSession->setLastRealOrderId((string) $order->getIncrementId());
        $this->checkoutSession->setLastQuoteId((int) $order->getQuoteId());
        $this->checkoutSession->setLastSuccessQuoteId((int) $order->getQuoteId());
        $this->checkoutSession->setLastOrderStatus((string) $order->getStatus());
    }
}
