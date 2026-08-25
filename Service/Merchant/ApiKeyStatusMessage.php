<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Merchant;

use Magento\Framework\Phrase;
use Two\Gateway\Api\BrandRegistryInterface;

/**
 * Merchant-facing wording for an {@see ApiKeyStatus} verdict.
 *
 * The single owner of that wording: the settings-page renderer, the live
 * verification endpoint and the save-time guard all describe the same
 * verdict, and a category that reads "the key is wrong" on one surface and
 * "we could not reach the service" on another sends an admin after the
 * wrong fix.
 *
 * The upstream response body is never part of a message — only the
 * category and, where an HTTP exchange completed, its status code.
 */
class ApiKeyStatusMessage
{
    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    /**
     * @param BrandRegistryInterface $brandRegistry
     */
    public function __construct(BrandRegistryInterface $brandRegistry)
    {
        $this->brandRegistry = $brandRegistry;
    }

    /**
     * ['message' => Phrase, 'status' => 'success'|'warning'|'error'], plus
     * 'merchant_id' and 'merchant_short_name' on success only.
     *
     * @param array{status?: string, code?: int|null, merchant?: array<string,mixed>|null} $result
     * @return array{message: Phrase, status: string, merchant_id?: string, merchant_short_name?: string}
     */
    public function describe(array $result): array
    {
        $productName = $this->brandRegistry->getProductName();
        $code = (string)($result['code'] ?? '');

        switch ($result['status'] ?? '') {
            case ApiKeyStatus::OK:
                // verify_api_key returns {id, short_name}; surface both so the
                // merchant can confirm at a glance which account the key resolves to.
                $merchant = $result['merchant'] ?? [];
                return [
                    'message' => __('API key is valid'),
                    'status' => 'success',
                    'merchant_id' => isset($merchant['id']) ? (string)$merchant['id'] : '',
                    'merchant_short_name' => isset($merchant['short_name'])
                        ? (string)$merchant['short_name'] : ''
                ];

            case ApiKeyStatus::NOT_CONFIGURED:
                return [
                    'message' => __('API key is missing'),
                    'status' => 'warning'
                ];

            case ApiKeyStatus::INVALID_KEY:
                return [
                    'message' => __('This API key was rejected by %1. It may be invalid, expired, or issued for a different environment.', $productName),
                    'status' => 'error'
                ];

            case ApiKeyStatus::SERVICE_ERROR:
                return [
                    'message' => __('%1 could not verify this API key because the service returned an error (HTTP %2). The key itself may be fine, so please try again shortly.', $productName, $code),
                    'status' => 'error'
                ];

            case ApiKeyStatus::UNREACHABLE:
                return [
                    'message' => __('%1 could not be reached to verify this API key. This is a connection problem rather than necessarily a problem with the key. Check that this store can make outbound requests, then try again.', $productName),
                    'status' => 'error'
                ];

            case ApiKeyStatus::MALFORMED_RESPONSE:
                return [
                    'message' => __('%1 returned an unexpected response while verifying this API key, so it could not be confirmed. Please try again shortly.', $productName),
                    'status' => 'error'
                ];

            default:
                return [
                    'message' => __('This API key could not be verified by %1 (HTTP %2).', $productName, $code),
                    'status' => 'error'
                ];
        }
    }
}
