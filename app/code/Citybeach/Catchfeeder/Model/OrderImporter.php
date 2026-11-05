<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Model;

use Citybeach\Catchfeeder\Api\OrderImportInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Address\RateFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

class OrderImporter implements OrderImportInterface
{
    public function __construct(
        private readonly QuoteFactory $quoteFactory,
        private readonly QuoteManagement $quoteManagement,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly RateFactory $rateFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RegionFactory $regionFactory,
        private readonly ResourceConnection $resource
    ) {
    }

    public function jsonToOrder(string $jsonOrder, int $storeId, bool $guestCheckout): string
    {
        $payload = json_decode($jsonOrder);
        if (!is_object($payload)) {
            throw new LocalizedException(__('Catch order JSON could not be read.'));
        }
        $store = $this->storeManager->getStore($storeId);
        $baseCurrency = (string) $store->getBaseCurrencyCode();
        if (($payload->currency ?? '') !== $baseCurrency) {
            throw new LocalizedException(__('Order currency does not match the store base currency.'));
        }
        $this->require($payload, ['customer', 'billingAddress', 'shippingAddress', 'products', 'payment']);

        $reference = $this->reference($payload);
        $existing = $this->existingIncrementId($reference);
        if ($existing !== null) {
            return $existing;
        }

        $quote = $this->quoteFactory->create();
        $quote->setStoreId($storeId);
        $quote->setIsActive(false);
        $quote->setData('catchfeeder_import', true);
        $quote->setBaseCurrencyCode($baseCurrency);
        $quote->setQuoteCurrencyCode($baseCurrency);
        $quote->setStoreCurrencyCode($baseCurrency);
        $this->assignCustomer($quote, $payload, $guestCheckout, (int) $store->getWebsiteId());
        $email = (string) $payload->customer->email;
        $quote->getBillingAddress()->addData($this->addressData($payload->billingAddress, $email));
        $shipping = $quote->getShippingAddress();
        $shipping->addData($this->addressData($payload->shippingAddress, $email));

        foreach ($payload->products as $line) {
            $product = $this->productRepository->getById((int) $line->externalId, false, $storeId);
            if (in_array($product->getTypeId(), [ProductType::TYPE_BUNDLE, 'configurable', 'grouped'], true)) {
                throw new LocalizedException(
                    __('Catch line %1 must be a simple product. Parent products cannot be ordered directly.', $product->getSku())
                );
            }
            $product->setData('salable', true);
            $result = $quote->addProduct($product, (float) $line->quantity);
            if (is_string($result)) {
                throw new LocalizedException(__('%1', $result));
            }
            $item = $quote->getItemByProduct($product);
            if ($item) {
                $item->setCustomPrice((float) $line->unitPrice);
                $item->setOriginalCustomPrice((float) $line->unitPrice);
                $item->getProduct()->setIsSuperMode(true);
            }
        }

        $carrier = (string) ($payload->shippingCarrierCode ?? '');
        $method = (string) ($payload->shippingMethodCode ?? '');
        $code = $carrier . '_' . $method;
        $rate = $this->rateFactory->create();
        $rate->setCode($code);
        $rate->setCarrier($carrier);
        $rate->setCarrierTitle($carrier);
        $rate->setMethod($method);
        $rate->setMethodTitle($method);
        $rate->setPrice((float) ($payload->shippingPrice ?? 0));
        $rate->setCost(0);
        $shipping->addShippingRate($rate);
        $shipping->setShippingMethod($code);
        $shipping->setShippingDescription(trim($carrier . ' - ' . $method, ' -'));
        $shipping->setCollectShippingRates(false);

        $paymentData = $payload->payment;
        $poNumber = (string) ($paymentData->transactionId ?? '');
        if (($paymentData->paymentType ?? '') === 'paypal') {
            $poNumber = $poNumber . ' : ' . (string) ($paymentData->paypalPayerId ?? '');
        }
        $payment = $quote->getPayment();
        $payment->importData(['method' => CatchPayment::CODE]);
        $payment->setPoNumber($reference !== '' ? $reference : $poNumber);
        $payment->setAdditionalInformation('catch_transaction', $poNumber);
        $quote->collectTotals();
        $quote->save();

        $order = $this->quoteManagement->submit($quote);
        if ($order === null) {
            throw new LocalizedException(__('Catch order could not be created.'));
        }
        if (!empty($payload->deliveryInstructions)) {
            $order->addCommentToStatusHistory((string) __('Delivery Instructions: %1', $payload->deliveryInstructions));
        }
        if (!empty($payload->marketplaceCode)) {
            $order->addCommentToStatusHistory((string) __('Marketplace: %1', $payload->marketplaceCode));
        }
        if (!empty($payload->marketplaceOrderNumber)) {
            $order->addCommentToStatusHistory((string) __('Marketplace order: %1', $payload->marketplaceOrderNumber));
        }
        if ($poNumber !== '') {
            $order->addCommentToStatusHistory((string) __('Catch payment reference: %1', $poNumber));
        }
        $this->orderRepository->save($order);

        return (string) $order->getIncrementId();
    }

    private function reference(\stdClass $payload): string
    {
        $marketplace = trim((string) ($payload->marketplaceOrderNumber ?? ''));
        if ($marketplace !== '') {
            return $marketplace;
        }

        return trim((string) ($payload->payment->transactionId ?? ''));
    }

    private function existingIncrementId(string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }
        $connection = $this->resource->getConnection();
        $payment = $this->resource->getTableName('sales_order_payment');
        $order = $this->resource->getTableName('sales_order');
        $value = $connection->fetchOne(
            $connection->select()
                ->from(['p' => $payment], [])
                ->join(['o' => $order], 'o.entity_id = p.parent_id', ['increment_id'])
                ->where('p.method = ?', CatchPayment::CODE)
                ->where('p.po_number = ?', $reference)
                ->limit(1)
        );

        return $value === false || $value === null ? null : (string) $value;
    }

    private function assignCustomer(
        \Magento\Quote\Model\Quote $quote,
        \stdClass $payload,
        bool $guestCheckout,
        int $websiteId
    ): void {
        $email = (string) $payload->customer->email;
        $first = trim((string) ($payload->customer->firstName ?? ''));
        $last = trim((string) ($payload->customer->lastName ?? ''));
        if ($first === '') {
            $first = 'Customer';
        }
        if ($last === '') {
            $last = $first;
        }
        if (!$guestCheckout) {
            try {
                $customer = $this->customerRepository->get($email, $websiteId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('No customer exists for this Catch order.'));
            }
            $quote->assignCustomer($customer);
            return;
        }
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail($email);
        $quote->setCustomerFirstname($first);
        $quote->setCustomerLastname($last);
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
    }

    /**
     * @return array<string, mixed>
     */
    private function addressData(\stdClass $address, string $email): array
    {
        $street = [trim((string) ($address->line1 ?? ''))];
        if ($street[0] === '') {
            $street[0] = 'Not supplied';
        }
        if (!empty($address->line2)) {
            $street[] = (string) $address->line2;
        }
        $countryId = strtoupper(trim((string) ($address->countryCode ?? '')));
        $region = $this->region($countryId, (string) ($address->state ?? ''));
        $first = trim((string) ($address->firstName ?? ''));
        $last = trim((string) ($address->lastName ?? ''));
        if ($first === '') {
            $first = 'Customer';
        }
        if ($last === '') {
            $last = $first;
        }
        $city = trim((string) ($address->city ?? ''));
        $postcode = trim((string) ($address->postcode ?? ''));

        return [
            'firstname' => $first,
            'lastname' => $last,
            'street' => $street,
            'city' => $city !== '' ? $city : 'Not supplied',
            'country_id' => $countryId,
            'postcode' => $postcode !== '' ? $postcode : '0000',
            'telephone' => trim((string) ($address->phone ?? '')) !== '' ? (string) $address->phone : '0',
            'region' => $region['name'],
            'region_id' => $region['id'],
            'email' => $email,
        ];
    }

    /**
     * @return array{id: int|null, name: string}
     */
    private function region(string $countryId, string $state): array
    {
        $state = trim($state);
        if ($countryId === '' || $state === '') {
            return ['id' => null, 'name' => $state];
        }
        $region = $this->regionFactory->create();
        $region->loadByCode(strtoupper($state), $countryId);
        if (!$region->getId()) {
            $region->loadByName($state, $countryId);
        }
        if ($region->getId()) {
            return ['id' => (int) $region->getId(), 'name' => (string) $region->getName()];
        }

        return ['id' => null, 'name' => $state];
    }

    /**
     * @param string[] $fields
     */
    private function require(\stdClass $payload, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($payload->{$field})) {
                throw new LocalizedException(__('Catch order is missing %1.', $field));
            }
        }
    }
}
