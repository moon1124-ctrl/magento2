<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\ScopeInterface;
use SecurePay\SecureFrame\Model\RequestBuilder;

class Response extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private readonly OrderFactory $orderFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly RequestBuilder $requestBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutSession $checkoutSession
    ) {
        parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        if (!$this->getRequest()->isPost()) {
            return $redirect->setPath('');
        }

        $posted = $this->getRequest()->getPostValue();
        $incrementId = (string) ($posted['refid'] ?? '');
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        if (!$order->getId()) {
            $this->messageManager->addErrorMessage(__('The payment result could not be matched to an order.'));
            return $redirect->setPath('checkout/onepage/failure');
        }

        $storeId = (int) $order->getStoreId();
        $merchant = (string) $this->scopeConfig->getValue(
            'payment/secureframe/merchant_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $amount = (string) ($posted['amount'] ?? '');
        $expected = $this->requestBuilder->responseFingerprint(
            $merchant,
            $this->requestBuilder->secret($storeId),
            $incrementId,
            $amount,
            (string) ($posted['timestamp'] ?? ''),
            (string) ($posted['summarycode'] ?? '')
        );

        $postedFingerprint = (string) ($posted['fingerprint'] ?? '');
        if (strlen($postedFingerprint) !== strlen($expected) || !hash_equals($expected, $postedFingerprint)) {
            $this->messageManager->addErrorMessage(__('Transaction result could not be read.'));
            return $redirect->setPath('checkout/onepage/failure');
        }

        $approved = (string) ($posted['summarycode'] ?? '') === '1';
        $isCallback = (bool) $this->getRequest()->getParam('callback');

        if (!$approved) {
            if (!$isCallback && $order->canCancel()) {
                $order->cancel();
                $order->addCommentToStatusHistory(__(
                    'Payment was declined. Reason: %1 (%2)',
                    (string) ($posted['restext'] ?? ''),
                    (string) ($posted['rescode'] ?? '')
                ));
                $this->orderRepository->save($order);
                $this->rememberSuccess($order);
                $this->checkoutSession->restoreQuote();
            }
            $this->messageManager->addErrorMessage(__('Your transaction was declined.'));
            return $redirect->setPath('checkout/onepage/failure');
        }

        $this->capture($order, $posted);
        if (!$isCallback) {
            $this->rememberSuccess($order);
        }
        return $redirect->setPath('checkout/onepage/success');
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function capture(Order $order, array $posted): void
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return;
        }
        $txnId = (string) ($posted['txnid'] ?? '');
        $transactionId = $order->getIncrementId() . '_' . $txnId;
        if ((string) $payment->getLastTransId() === $transactionId) {
            return;
        }

        $dollars = ((float) ($posted['amount'] ?? 0)) / 100;
        $payment->setTransactionId($transactionId);
        $payment->setParentTransactionId($txnId);
        $payment->setIsTransactionClosed(false);
        $payment->registerCaptureNotification($dollars);
        $order->addCommentToStatusHistory(__('Gateway has authorized the payment.'));
        if (!empty($posted['afrescode'])) {
            $order->setStatus(Order::STATUS_FRAUD);
            $order->addCommentToStatusHistory(__(
                'SecurePay FraudGuard result: %1 (%2)',
                (string) ($posted['afrestext'] ?? ''),
                (string) $posted['afrescode']
            ));
        }
        if (!$order->getEmailSent()) {
            $this->orderSender->send($order);
        }
        $this->orderRepository->save($order);
    }

    /**
     * SecurePay posts the browser back from another site, so the original checkout
     * cookie is not included. The success page reads these values from the session
     * started by this response.
     */
    private function rememberSuccess(Order $order): void
    {
        $this->checkoutSession->setLastOrderId((int) $order->getEntityId());
        $this->checkoutSession->setLastRealOrderId((string) $order->getIncrementId());
        $this->checkoutSession->setLastQuoteId((int) $order->getQuoteId());
        $this->checkoutSession->setLastSuccessQuoteId((int) $order->getQuoteId());
        $this->checkoutSession->setLastOrderStatus((string) $order->getStatus());
    }
}
