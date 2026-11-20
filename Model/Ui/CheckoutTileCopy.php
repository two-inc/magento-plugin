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
        private readonly BrandRegistryInterface $brandRegistry,
        private readonly AnchorOnlyHtmlEscaper $htmlEscaper
    ) {
    }

    /**
     * Renderers bind the result unescaped, so it is escaped here: the merchant
     * override (TWO-25386) as plain text, and the brand tagline, a translation
     * key whose %1/%2 the FAQ URL fills, through AnchorOnlyHtmlEscaper so its
     * one link survives. The override wins over the tagline.
     */
    public function getSubtitleHtml(): string
    {
        // Emptiness is judged after escaping: copy that is only markup the
        // escaper drops would otherwise emit a blank subtitle element.
        // The merchant subtitle is plain text (TWO-27108): markup a subtitle
        // saved before that rule still carries is removed, its words kept.
        $configured = trim($this->htmlEscaper->escapeTextOnly(strip_tags($this->configRepository->getSubtitle())));
        if ($configured !== '') {
            return $configured;
        }

        $key = $this->brandRegistry->getCheckoutSubtitle();
        $faqUrl = $this->httpUrlOrEmpty($this->brandRegistry->getCheckoutSubtitleFaqUrl());
        if ($key === '' || $faqUrl === '') {
            return '';
        }

        return $this->htmlEscaper->escape((string)__(
            $key,
            '<a href="' . htmlspecialchars($faqUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">',
            '</a>'
        ));
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
        return $this->isAboutLinkVisible()
            ? (string)__('What is %1?', $this->brandRegistry->getProductName())
            : '';
    }

    /**
     * The tooltip body for the about icon; empty whenever the icon itself is
     * withheld. The closing line is plain text — the icon is the link, so an
     * anchor here would be a second, duplicate one. Renderers bind the result
     * unescaped, so every phrase is escaped here.
     */
    public function getAboutTooltipHtml(): string
    {
        if (!$this->isAboutLinkVisible()) {
            return '';
        }

        $product = $this->brandRegistry->getProductName();

        // One literal per phrase: Magento's i18n scanner cannot harvest a concatenated key.
        return '<p>' . $this->htmlEscaper->escapeTextOnly((string)__('%1 is a payment solution for B2B purchases online, allowing you to buy from your favourite merchants and suppliers on trade credit. Using %1, you can access flexible trade credit instantly to make purchasing simple.', $product)) . '</p>'
            . '<p><strong>' . $this->htmlEscaper->escapeTextOnly((string)__('Buy now, receive your goods, pay your invoice later.')) . '</strong></p>'
            . '<p>' . $this->htmlEscaper->escapeTextOnly((string)__('Click to find out more')) . '</p>';
    }
}
