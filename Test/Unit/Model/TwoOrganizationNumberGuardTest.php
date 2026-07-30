<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Two;

/**
 * Server-side floor for the buyer's company number.
 *
 * The client-side validator on the checkout field is the good UX path, but
 * it is not enforcement: any checkout that does not render that field, or
 * any client that skips it, previously reached the order request with an
 * empty company number. These tests pin the refusal that happens before
 * the request is built.
 */
class TwoOrganizationNumberGuardTest extends TestCase
{
    private const MSGID = 'Invoice purchase with %1 requires a company number.'
        . ' Please add your company details and try again.';

    /** @var Two */
    private $model;

    protected function setUp(): void
    {
        $this->model = $this->getMockBuilder(Two::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');

        $ref = new \ReflectionClass(Two::class);
        $prop = $ref->getProperty('brandRegistry');
        $prop->setValue($this->model, $brand);
    }

    /**
     * @param array $additionalInformation
     */
    private function invokeGuard(array $additionalInformation): void
    {
        $method = new \ReflectionMethod(Two::class, 'assertOrganizationNumberPresent');
        $method->invoke($this->model, $additionalInformation);
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function refusingPayloadProvider(): array
    {
        return [
            'key absent entirely' => [['companyName' => 'Example Trading Ltd']],
            'empty string' => [['companyId' => '']],
            'null' => [['companyId' => null]],
            'single space' => [['companyId' => ' ']],
            'tabs and newlines only' => [['companyId' => "\t\n "]],
            'array instead of scalar' => [['companyId' => []]],
        ];
    }

    /**
     * @dataProvider refusingPayloadProvider
     */
    public function testGuardRefusesWhenCompanyNumberIsMissing(array $additionalInformation): void
    {
        $this->expectException(LocalizedException::class);
        $this->invokeGuard($additionalInformation);
    }

    /**
     * Pin the whole shipped string, not a substring of it. The harness
     * substitutes placeholders faithfully, so the rendered message is the
     * msgid with the brand name in place of %1 — asserting equality against
     * that pins both the wording that goes in the translation catalogues
     * and the fact that the brand name is passed as an argument.
     */
    public function testRefusalNamesTheCompanyNumberAndTheBrand(): void
    {
        try {
            $this->invokeGuard(['companyId' => '']);
            $this->fail('Expected the guard to refuse an empty company number.');
        } catch (LocalizedException $e) {
            $this->assertSame(str_replace('%1', 'Two', self::MSGID), $e->getMessage());
        }
    }

    /**
     * The guard is worthless unless authorize() actually calls it, and calls
     * it BEFORE the order request is composed — refusing after the request
     * has been built is the very thing this replaces. authorize() needs the
     * full framework to execute, so pin the wiring by reading the method's
     * own source rather than running it.
     *
     * Ordering alone is not enough: a call that hands the guard some other
     * array — an empty literal, say — sits in the right place and refuses
     * every order, so the argument is pinned too. It must be the same
     * payload authorize() goes on to build the order request from.
     */
    public function testAuthorizeInvokesTheGuardBeforeComposingTheRequest(): void
    {
        $method = new \ReflectionMethod(Two::class, 'authorize');
        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $guardAt = strpos($source, 'assertOrganizationNumberPresent(');
        $composeAt = strpos($source, 'compositeOrder->execute(');

        $this->assertNotFalse($guardAt, 'authorize() must call the company-number guard.');
        $this->assertSame(
            1,
            preg_match_all('/assertOrganizationNumberPresent\(([^)]*)\)/', $source, $calls),
            'authorize() must call the company-number guard exactly once, with a simple argument.'
        );
        $this->assertSame(
            '$additionalInformation',
            $calls[1][0],
            'The guard must be handed the checkout payload authorize() composes the order'
            . ' request from, not some other value.'
        );
        $this->assertNotFalse($composeAt, 'authorize() must still compose the order request.');
        $this->assertLessThan(
            $composeAt,
            $guardAt,
            'The company-number guard must run before the order request is composed.'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function acceptedCompanyNumberProvider(): array
    {
        return [
            'plain digits' => ['123456789'],
            'formatted with spaces' => ['123 456 789'],
            'country-prefixed' => ['NO123456789MVA'],
            'leading and trailing whitespace' => ['  123456789  '],
            'integer rather than string' => [123456789],
        ];
    }

    /**
     * @dataProvider acceptedCompanyNumberProvider
     * @param mixed $companyId
     */
    public function testGuardAllowsAPopulatedCompanyNumber($companyId): void
    {
        $this->invokeGuard(['companyId' => $companyId]);
        $this->addToAssertionCount(1);
    }
}
