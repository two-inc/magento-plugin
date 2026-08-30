<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\AdminNotification;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Notification\MessageInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Warns that the checkout rate limit currently counts every buyer behind a
 * CDN, load balancer or reverse proxy as one caller, which refuses them
 * mid-checkout. Shown on upgrade because the ceiling arrives switched on with
 * no trusted-proxy list, and clears itself once either is addressed.
 */
class TrustedProxiesMessage implements MessageInterface
{
    private const SETTINGS_PATH = 'adminhtml/system_config/edit/section/two_general';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function getIdentity(): string
    {
        return 'two_gateway_trusted_proxies_unset';
    }

    public function isDisplayed(): bool
    {
        return !$this->configRepository->isRateLimitDisabled()
            && $this->configRepository->getTrustedProxies() === [];
    }

    public function getText(): string
    {
        return (string)__(
            'Two: checkout rate limiting is on and no trusted proxies are set. If this store sits behind a '
            . 'CDN, load balancer or reverse proxy, every buyer reaches it as one address and shares a single '
            . 'request ceiling, so buyers can be refused mid-checkout. <a href="%1">Set Trusted proxies</a>, '
            . 'or leave this if the store is reached directly.',
            $this->backendUrl->getUrl(self::SETTINGS_PATH)
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
