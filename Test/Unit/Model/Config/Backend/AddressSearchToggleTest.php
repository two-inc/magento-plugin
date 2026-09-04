<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\AddressSearchToggle;

/**
 * TWO-25503: `enable_address_search` can never be stored ON while its
 * sibling `enable_company_search` is submitted OFF.
 */
class AddressSearchToggleTest extends TestCase
{
    private function buildModel(array $data): AddressSearchToggle
    {
        return new AddressSearchToggle(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            null,
            null,
            $data
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function submissionsProvider(): array
    {
        // submitted enable_company_search, submitted enable_address_search, expected stored value.
        return [
            'company on, address on stays on' => ['1', '1', '1'],
            'company on, address off stays off' => ['1', '0', '0'],
            'company off forces address off' => ['0', '1', '0'],
            'company off, address already off stays off' => ['0', '0', '0'],
        ];
    }

    /**
     * @dataProvider submissionsProvider
     */
    public function testCompanySearchOffForcesAddressSearchOff(
        string $companySubmitted,
        string $addressSubmitted,
        string $expected
    ): void {
        $model = $this->buildModel([
            'value' => $addressSubmitted,
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => ['enable_company_search' => $companySubmitted],
        ]);

        $model->beforeSave();

        $this->assertSame($expected, $model->getValue());
    }

    /**
     * A request that does not carry the sibling at all (e.g. a programmatic
     * write of this field alone) has nothing to force off against, so the
     * submitted value is left untouched.
     */
    public function testLeavesValueUntouchedWhenCompanySearchIsNotOnThisRequest(): void
    {
        $model = $this->buildModel([
            'value' => '1',
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => [],
        ]);

        $model->beforeSave();

        $this->assertSame('1', $model->getValue());
    }
}
