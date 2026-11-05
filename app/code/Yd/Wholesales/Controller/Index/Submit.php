<?php

declare(strict_types=1);

namespace Yd\Wholesales\Controller\Index;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Yd\Wholesales\Model\ApplicationMailer;
use Yd\Wholesales\Model\SpamGuard;

class Submit implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly SpamGuard $spamGuard,
        private readonly ApplicationMailer $mailer
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create()->setPath('wholesales/index/form');
        $data = $this->request->getParams();

        if ($this->spamGuard->isSpam($data)) {
            $this->messageManager->addErrorMessage(__('The application could not be sent.'));
            return $redirect;
        }

        $company = trim((string) ($data['compName'] ?? ''));
        $email = trim((string) ($data['wsEmail'] ?? ''));
        $mobile = trim((string) ($data['mobileNum'] ?? ''));
        if ($company === '' || $email === '' || $mobile === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('Company name, email, and mobile are required.'));
            return $redirect;
        }

        try {
            $this->mailer->send($data);
        } catch (\Throwable) {
            $this->messageManager->addErrorMessage(__('The application could not be sent. Please try again later.'));
            return $redirect;
        }

        $this->messageManager->addSuccessMessage(__('Your wholesale application has been sent.'));
        return $redirect;
    }
}
