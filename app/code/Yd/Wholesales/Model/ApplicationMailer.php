<?php

declare(strict_types=1);

namespace Yd\Wholesales\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class ApplicationMailer
{
    private const XML_RECIPIENT = 'yd_wholesales/email/recipient';
    private const XML_RECIPIENT_NAME = 'yd_wholesales/email/recipient_name';
    private const XML_IDENTITY = 'yd_wholesales/email/identity';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function send(array $data): void
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $recipient = trim((string) $this->scopeConfig->getValue(self::XML_RECIPIENT, ScopeInterface::SCOPE_STORE, $storeId));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Wholesale notification email is not configured.'));
        }

        $recipientName = trim((string) $this->scopeConfig->getValue(
            self::XML_RECIPIENT_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        $identity = (string) $this->scopeConfig->getValue(self::XML_IDENTITY, ScopeInterface::SCOPE_STORE, $storeId);
        if ($identity === '') {
            $identity = 'general';
        }

        $transport = $this->transportBuilder
            ->setTemplateIdentifier('yd_wholesales_application')
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars($this->vars($data))
            ->setFromByScope($identity, $storeId)
            ->addTo($recipient, $recipientName !== '' ? $recipientName : $recipient)
            ->getTransport();
        $transport->sendMessage();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function vars(array $data): array
    {
        $map = [
            'company_name' => 'compName',
            'trading_name' => 'tradeName',
            'abn' => 'abn',
            'acn' => 'acn',
            'email' => 'wsEmail',
            'phone' => 'phoneNum',
            'mobile' => 'mobileNum',
            'trading_address' => 'tradeAdd',
            'suburb' => 'suburb',
            'country' => 'wsCountry',
            'postcode' => 'wsPostcode',
            'website' => 'wsWebsite',
            'monthly_spend' => 'monthlyParts',
        ];
        $vars = [];
        foreach ($map as $var => $field) {
            $vars[$var] = trim((string) ($data[$field] ?? ''));
        }

        return $vars;
    }
}
