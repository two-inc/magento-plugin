<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Ui;

use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/** Checkout tile subtitle and about-link copy; no quote, session or API dependency — the Hyva renderer resolves it per payment-list render. */
class CheckoutTileCopy
{
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly BrandRegistryInterface $brandRegistry
    ) {
    }

    /**
     * May contain HTML; renderers bind it unescaped. The merchant override
     * (TWO-25386) is free text, so it is escaped and never translated; the
     * brand tagline is a translation key whose %1/%2 the FAQ URL fills.
     */
    public function getSubtitleHtml(): string
    {
        $configured = trim($this->configRepository->getSubtitle());
        if ($configured !== '') {
            return htmlspecialchars($configured, ENT_QUOTES, 'UTF-8');
        }

        $key = $this->brandRegistry->getCheckoutSubtitle();
        $faqUrl = $this->httpUrlOrEmpty($this->brandRegistry->getCheckoutSubtitleFaqUrl());
        if ($key === '' || $faqUrl === '') {
            return '';
        }

        return (string)__(
            $key,
            '<a href="' . htmlspecialchars($faqUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">',
            '</a>'
        );
    }

    /** The merchant toggle can only hide the link, never give it a target. */
    public function isAboutLinkVisible(): bool
    {
        return $this->configRepository->isAboutLinkEnabled()
            && $this->httpUrlOrEmpty($this->brandRegistry->getAboutUrl()) !== '';
    }

    public function getAboutLinkUrl(): string
    {
        return $this->isAboutLinkVisible()
            ? $this->httpUrlOrEmpty($this->brandRegistry->getAboutUrl())
            : '';
    }

    /** A brand URL is buyer-facing markup, so anything but http(s) — javascript:, data: — resolves to no link. */
    private function httpUrlOrEmpty(string $url): string
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https' ? $url : '';
    }

    public function getAboutLinkText(): string
    {
        return (string)__('What is %1?', $this->brandRegistry->getProductName());
    }
}
