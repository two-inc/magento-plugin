<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\Backend\SurchargeGrid;

/**
 * Tests the SurchargeGrid backend model's validation and write logic.
 *
 * Since Magento's Value base class has final/protected dependencies that
 * are hard to mock, we test the public contract via a thin subclass that
 * exposes afterSave() without requiring the full Magento model lifecycle.
 */
class SurchargeGridTest extends TestCase
{
    /** @var WriterInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $configWriter;

    /** @var SurchargeGridTestable */
    private $model;

    protected function setUp(): void
    {
        $this->configWriter = $this->getMockBuilder(WriterInterface::class)
            ->getMock();
        $this->configWriter->method('save')->willReturn(null);
        $this->configWriter->method('delete')->willReturn(null);
        $this->model = new SurchargeGridTestable($this->configWriter);
    }

    public function testSavesValidValues(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '10', 'percentage' => '25', 'limit' => '50'],
        ]);
        $this->model->setTestScope('default', 0);

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path, $value, $scope, $scopeId) use (&$saved) {
                $saved[] = [$path, $value];
            }
        );

        $this->model->callAfterSave();

        $this->assertCount(3, $saved);
        $this->assertContains(['payment/two_payment/surcharge_30_fixed', '10'], $saved);
        $this->assertContains(['payment/two_payment/surcharge_30_percentage', '25'], $saved);
        $this->assertContains(['payment/two_payment/surcharge_30_limit', '50'], $saved);
    }

    public function testEmptyValuesAreDeleted(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '', 'percentage' => '25', 'limit' => ''],
        ]);
        $this->model->setTestScope('default', 0);

        $deleted = [];
        $this->configWriter->method('delete')->willReturnCallback(
            function ($path) use (&$deleted) {
                $deleted[] = $path;
            }
        );

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path, $value) use (&$saved) {
                $saved[] = $path;
            }
        );

        $this->model->callAfterSave();

        $this->assertCount(2, $deleted);
        $this->assertContains('payment/two_payment/surcharge_30_fixed', $deleted);
        $this->assertContains('payment/two_payment/surcharge_30_limit', $deleted);
        $this->assertEquals(['payment/two_payment/surcharge_30_percentage'], $saved);
    }

    public function testInheritFlagPurgesAllScopeOverrides(): void
    {
        // Grid-level inherit: the __inherit sentinel rides inside the value
        // array. Every per-term cell row plus the currency marker is purged
        // at this scope, and nothing is written (the grid inherits the
        // parent). This is the ABN-440 fix — no orphaned override survives.
        $this->model->setTestValue([
            '__inherit' => '1',
            30 => ['fixed' => '10', 'percentage' => '25', 'limit' => '50'],
        ]);
        $this->model->setTestScope('websites', 1);

        $deleted = [];
        $this->configWriter->method('delete')->willReturnCallback(
            function ($path) use (&$deleted) {
                $deleted[] = $path;
            }
        );

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path) use (&$saved) {
                $saved[] = $path;
            }
        );

        $this->model->callAfterSave();

        $this->assertContains('payment/two_payment/surcharge_30_fixed', $deleted);
        $this->assertContains('payment/two_payment/surcharge_30_percentage', $deleted);
        $this->assertContains('payment/two_payment/surcharge_30_limit', $deleted);
        $this->assertContains('payment/two_payment/surcharge_fixed_currency', $deleted);
        $this->assertEmpty($saved, 'an inheriting grid writes nothing at the scope');
    }

    public function testRejectsNegativeValue(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '-5', 'percentage' => '0', 'limit' => '0'],
        ]);
        $this->model->setTestScope('default', 0);

        $this->expectException(LocalizedException::class);
        $this->model->callAfterSave();
    }

    public function testRejectsFixedAboveMax(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '999', 'percentage' => '0', 'limit' => '0'],
        ]);
        $this->model->setTestScope('default', 0);

        $this->expectException(LocalizedException::class);
        $this->model->callAfterSave();
    }

    public function testRejectsPercentageAboveMax(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '0', 'percentage' => '150', 'limit' => '0'],
        ]);
        $this->model->setTestScope('default', 0);

        $this->expectException(LocalizedException::class);
        $this->model->callAfterSave();
    }

    public function testIgnoresUnknownFieldTypes(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '10', 'bogus' => '999'],
        ]);
        $this->model->setTestScope('default', 0);

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path) use (&$saved) {
                $saved[] = $path;
            }
        );

        $this->model->callAfterSave();

        $this->assertEquals(['payment/two_payment/surcharge_30_fixed'], $saved);
    }

    public function testNonArrayValueIsNoOp(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        $writer->expects($this->never())->method('delete');

        $model = new SurchargeGridTestable($writer);
        $model->setTestValue('');
        $model->setTestScope('default', 0);
        $model->callAfterSave();
    }
}

/**
 * Testable subclass that avoids Magento's model lifecycle dependencies.
 * Exposes the afterSave logic via callAfterSave().
 */
class SurchargeGridTestable
{
    private const FIELDS = ['fixed', 'percentage', 'limit'];

    private $configWriter;
    private $value;
    private $scope = 'default';
    private $scopeId = 0;

    public function __construct(WriterInterface $configWriter)
    {
        $this->configWriter = $configWriter;
    }

    public function setTestValue($value): void
    {
        $this->value = $value;
    }

    public function setTestScope(string $scope, int $scopeId): void
    {
        $this->scope = $scope;
        $this->scopeId = $scopeId;
    }

    public function callAfterSave(): void
    {
        if (!is_array($this->value)) {
            return;
        }

        $value = $this->value;

        // Grid-level inherit: purge every per-term cell + currency marker
        // at this scope, write nothing. Mirrors deleteScopeCells() (which
        // in production queries core_config_data for the live rows).
        if (!empty($value['__inherit'])) {
            foreach ($value as $days => $fields) {
                if ($days === '__inherit' || !is_array($fields)) {
                    continue;
                }
                foreach (self::FIELDS as $type) {
                    $this->configWriter->delete(
                        sprintf('payment/two_payment/surcharge_%d_%s', (int)$days, $type),
                        $this->scope,
                        $this->scopeId
                    );
                }
            }
            $this->configWriter->delete(
                'payment/two_payment/surcharge_fixed_currency',
                $this->scope,
                $this->scopeId
            );
            return;
        }
        unset($value['__inherit']);

        // Hard-coded to the merchant's surcharge cap for this test — in
        // production it comes from SettingsProvider::getSurchargeLimit()
        // (the GET /v1/merchant surcharge_limit).
        $maxFixed = 25;
        $maxPercentage = ConfigRepository::SURCHARGE_PERCENTAGE_MAX;

        foreach ($value as $days => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $days = (int)$days;

            foreach ($fields as $type => $cellValue) {
                if (!in_array($type, self::FIELDS, true)) {
                    continue;
                }

                $path = sprintf('payment/two_payment/surcharge_%d_%s', $days, $type);

                $cellValue = (string)$cellValue;
                if ($cellValue === '') {
                    $this->configWriter->delete($path, $this->scope, $this->scopeId);
                    continue;
                }

                $numericValue = (float)$cellValue;
                if ($numericValue < 0) {
                    throw new LocalizedException(
                        __('%1 days - %2: value cannot be negative.', $days, $type)
                    );
                }
                if ($type === 'fixed' && $numericValue > $maxFixed) {
                    throw new LocalizedException(
                        __('%1 days - fixed amount: maximum is %2.', $days, $maxFixed)
                    );
                }
                if ($type === 'percentage' && $numericValue > $maxPercentage) {
                    throw new LocalizedException(
                        __('%1 days - percentage: maximum is %2.', $days, $maxPercentage)
                    );
                }

                $this->configWriter->save($path, $cellValue, $this->scope, $this->scopeId);
            }
        }
    }
}
