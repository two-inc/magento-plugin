<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Two\Gateway\Api\Webapi\OrderIntentInterface;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\RateLimiter;

class OrderIntent implements OrderIntentInterface
{
    use UpstreamEnvelopeTrait;

    /**
     * One intent per checkout attempt, plus a re-check on each address or
     * total change — with headroom for a whole office sharing one NAT
     * address, which is the normal case for this plugin's B2B buyers.
     */
    private const LIMIT_PER_MINUTE = 60;

    private const WINDOW_SECONDS = 60;

    /** Comfortably past the largest cart this body describes. */
    private const MAX_PAYLOAD_BYTES = 262144;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly ApiKeyStatus $apiKeyStatus,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function place(string $payload): string
    {
        $this->rateLimiter->assertWithinLimit('two_order_intent', self::LIMIT_PER_MINUTE, self::WINDOW_SECONDS);

        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            return $this->refusal(413, (string)__('Invalid order intent payload.'));
        }

        $body = json_decode($payload, true);
        if (!is_array($body)) {
            return $this->refusal(400, (string)__('Invalid order intent payload.'));
        }

        $status = $this->apiKeyStatus->getStatus();
        $merchantId = $status['merchant']['id'] ?? null;
        if ($status['status'] !== ApiKeyStatus::OK || !is_string($merchantId) || $merchantId === '') {
            // A failed verification is cached for a minute, so sending the
            // merchant identity on regardless would turn one blip into a
            // window of upstream rejections the buyer reads as a decline.
            return $this->refusal(503, (string)__('The payment integration is not available right now.'));
        }

        $body['merchant_id'] = $merchantId;
        $body['merchant_short_name'] = $status['merchant']['short_name'] ?? null;

        return $this->envelope($this->adapter->executeWithStatus(self::ENDPOINT, $body));
    }
}
