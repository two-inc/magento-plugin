<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Magento\Framework\Exception\InputException;
use Two\Gateway\Api\Webapi\OrderIntentInterface;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

class OrderIntent implements OrderIntentInterface
{
    use UpstreamEnvelopeTrait;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly ApiKeyStatus $apiKeyStatus
    ) {
    }

    /**
     * @inheritDoc
     */
    public function place(string $payload): string
    {
        $body = json_decode($payload, true);
        if (!is_array($body)) {
            throw new InputException(__('Invalid order intent payload.'));
        }

        $status = $this->apiKeyStatus->getStatus();
        $merchant = $status['status'] === ApiKeyStatus::OK ? ($status['merchant'] ?? []) : [];
        $body['merchant_id'] = $merchant['id'] ?? null;
        $body['merchant_short_name'] = $merchant['short_name'] ?? null;

        return $this->envelope($this->adapter->execute(self::ENDPOINT, $body));
    }
}
