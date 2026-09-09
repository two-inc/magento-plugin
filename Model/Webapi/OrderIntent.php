<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\Webapi\OrderIntentInterface;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\RateLimiter;

class OrderIntent implements OrderIntentInterface
{
    use UpstreamEnvelopeTrait;

    /** Headroom for a whole office behind one NAT address. */
    private const LIMIT_PER_MINUTE = 60;

    private const WINDOW_SECONDS = 60;

    /** Comfortably past the largest cart this body describes. */
    private const MAX_PAYLOAD_BYTES = 262144;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly ApiKeyStatus $apiKeyStatus,
        private readonly SettingsProvider $settingsProvider,
        private readonly RateLimiter $rateLimiter,
        private readonly LogRepository $logRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly BuyerCountryResolver $buyerCountryResolver,
        private readonly SupportedCountriesProvider $supportedCountriesProvider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function place(string $payload): string
    {
        $this->rateLimiter->assertWithinLimit('two_order_intent', self::LIMIT_PER_MINUTE, self::WINDOW_SECONDS);

        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            $this->logRepository->addErrorLog(
                sprintf(
                    '[order-intent-oversize] bytes=%d cap=%d',
                    strlen($payload),
                    self::MAX_PAYLOAD_BYTES
                ),
                'Order intent refused before it was sent. Raise MAX_PAYLOAD_BYTES if legitimate carts reach this size.'
            );

            return $this->refusal(413, (string)__('This order is too large to send for approval.'));
        }

        $body = json_decode($payload, true);
        // A JSON list decodes to an array too, and merchant_id written onto one
        // would go upstream as an appended element rather than an identity.
        if (!is_array($body) || array_is_list($body)) {
            return $this->refusal(400, (string)__('Invalid order intent payload.'));
        }

        // ABN-533: only a definitive rejection refuses. An unreachable or
        // erroring Two says nothing about the key, and this route is reachable
        // whenever the tile is offered, so refusing here would put a red
        // unavailability notice on a correctly configured checkout.
        $identity = $this->merchantIdentity();
        if ($this->apiKeyStatus->isDefinitiveFailure() || $identity === null) {
            return $this->refusal(503, (string)__('The payment integration is not available right now.'));
        }
        $merchantId = $identity['id'];

        $storeId = $this->quoteStoreId();
        // Server-resolved, never the payload's own country_prefix — a buyer
        // must not be able to name the country their eligibility is judged on.
        $buyerCountry = $this->quoteBuyerCountry();
        if (!$this->supportedCountriesProvider->isAllowed($buyerCountry, $storeId)) {
            $this->logRepository->addLog(
                '[order-intent-country-refused] buyer country not supported',
                [
                    'merchant_id' => $merchantId,
                    'country' => $buyerCountry,
                    'restriction' => $this->supportedCountriesProvider->getState($storeId),
                ]
            );

            return $this->refusal(403, (string)__('The payment integration is not available right now.'));
        }

        $body['merchant_id'] = $merchantId;
        // Absent means absent — upstream reads an absent key and an explicit
        // null apart.
        unset($body['merchant_short_name']);
        if ($identity['short_name'] !== null) {
            $body['merchant_short_name'] = $identity['short_name'];
        }

        return $this->envelope(
            $this->adapter->executeWithStatus(self::ENDPOINT, $body, 'POST', $storeId)
        );
    }

    /**
     * The verdict's own merchant when it has one, else the never-expiring
     * record's — so an outage relays last-known-good rather than nothing.
     *
     * @return array{id: string, short_name: string|null}|null
     */
    private function merchantIdentity(): ?array
    {
        $merchant = $this->apiKeyStatus->getStatus()['merchant'] ?? null;
        $id = $merchant['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $shortName = $merchant['short_name'] ?? null;

            return [
                'id' => $id,
                'short_name' => is_string($shortName) && $shortName !== '' ? $shortName : null,
            ];
        }

        return $this->settingsProvider->getMerchantIdentity($this->quoteStoreId());
    }

    private function quoteBuyerCountry(): string
    {
        try {
            return $this->buyerCountryResolver->resolve($this->checkoutSession->getQuote());
        } catch (\Throwable $e) {
            return '';
        }
    }
}
