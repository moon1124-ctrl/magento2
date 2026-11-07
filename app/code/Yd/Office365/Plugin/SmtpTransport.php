<?php

declare(strict_types=1);

namespace Yd\Office365\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class SmtpTransport
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function aroundSendMessage(Transport $subject, callable $proceed): void
    {
        if (!$this->scopeConfig->isSetFlag('yd_office365/smtp/enabled')) {
            $proceed();
            return;
        }
        $message = $subject->getMessage();
        if (!$message instanceof EmailMessage) {
            $proceed();
            return;
        }
        $username = (string) $this->scopeConfig->getValue('yd_office365/smtp/username');
        $password = $this->password();
        $host = (string) $this->scopeConfig->getValue('yd_office365/smtp/host');
        $port = (int) $this->scopeConfig->getValue('yd_office365/smtp/port');
        if ($username === '' || $password === '' || $host === '') {
            $proceed();
            return;
        }
        $transport = new EsmtpTransport($host !== '' ? $host : 'smtp.office365.com', $port > 0 ? $port : 587);
        $transport->setUsername($username);
        $transport->setPassword($password);
        (new Mailer($transport))->send($message->getSymfonyMessage());
    }

    private function password(): string
    {
        $stored = (string) $this->scopeConfig->getValue('yd_office365/smtp/password');
        if ($stored === '') {
            return '';
        }
        $plain = $this->encryptor->decrypt($stored);

        return $plain !== '' ? $plain : $stored;
    }
}
