<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Test\Unit\Service\Order\Doubles\FixedVerdictFeeQuoteGate;

/**
 * TWO-27108: Two is offered only for a basket in a currency Two invoices in.
 */
class TwoCurrencyGateTest extends TestCase
{
    public static function currencies(): array
    {
        return [
            // basket currency, offered, description
            'NOK' => ['NOK', true, 'Norwegian krone is invoiced'],
            'GBP' => ['GBP', true, 'pound sterling is invoiced'],
            'SEK' => ['SEK', true, 'Swedish krona is invoiced'],
            'USD' => ['USD', true, 'US dollar is invoiced'],
            'DKK' => ['DKK', true, 'Danish krone is invoiced'],
            'EUR' => ['EUR', true, 'euro is invoiced'],
            'lower case' => ['eur', true, 'a code is compared case-insensitively'],
            'CHF' => ['CHF', false, 'Swiss franc is not invoiced, so Two is withheld'],
            'PLN' => ['PLN', false, 'zloty is not invoiced, so Two is withheld'],
            'unknown' => [null, true, 'no currency to judge: the method stays offered'],
        ];
    }

    #[DataProvider('currencies')]
    public function testOnlyACurrencyTwoInvoicesInIsOffered(?string $currency, bool $offered, string $description): void
    {
        $this->assertSame($offered, $this->build()->isAvailable($this->quote($currency)), $description);
    }

    private function build(): Two
    {
        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('test-api-key');
        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('isDefinitiveFailure')->willReturn(false);
        $minimumOrderGate = $this->createMock(MinimumOrderGate::class);
        $minimumOrderGate->method('isSatisfied')->willReturn(true);
        $countries = $this->createMock(SupportedCountriesProvider::class);
        $countries->method('isAllowed')->willReturn(true);
        $surcharge = $this->createMock(SurchargeCalculator::class);
        $surcharge->method('isSurchargeResolvable')->willReturn(true);

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'apiKeyStatus' => $apiKeyStatus,
            'logRepository' => $this->createMock(LogRepository::class),
            'configRepository' => $this->createMock(\Two\Gateway\Api\Config\RepositoryInterface::class),
            'feeQuoteGate' => new FixedVerdictFeeQuoteGate(true),
            'minimumOrderProvider' => $this->createMock(MinimumOrderProvider::class),
            'merchantMinimumResolver' => $this->createMock(MerchantMinimumResolver::class),
            'minimumOrderGate' => $minimumOrderGate,
            'amastyCheckoutStore' => [],
            'buyerCountryResolver' => new BuyerCountryResolver(),
            'supportedCountriesProvider' => $countries,
            'stubConfigData' => [],
            'surchargeCalculator' => $surcharge,
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        return $model;
    }

    private function quote(?string $currency): Quote
    {
        $address = $this->createMock(Address::class);
        $address->method('getCountryId')->willReturn('GB');
        $quote = $this->createMock(Quote::class);
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('getStore')->willReturn($this->createMock(Store::class));
        $quote->method('getQuoteCurrencyCode')->willReturn($currency);

        return $quote;
    }
}
