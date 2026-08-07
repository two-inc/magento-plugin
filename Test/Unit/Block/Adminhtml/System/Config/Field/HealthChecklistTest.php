<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Adminhtml\System\Config\Field\HealthChecklist;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * TWO-25386: the admin "Health checklist" panel, ported from
 * prestashop-plugin's renderTwoPluginHealthChecklist(). Same three checks
 * as that plugin: API key, environment, SSL verification.
 */
class HealthChecklistTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var ApiKeyStatus|\PHPUnit\Framework\MockObject\MockObject */
    private $apiKeyStatus;

    /** @var HealthChecklist */
    private $block;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->apiKeyStatus = $this->createMock(ApiKeyStatus::class);

        $this->block = new HealthChecklistTestable();
        $this->block->setDependencies($this->configRepository, $this->apiKeyStatus);
    }

    public function testAllHealthyRows(): void
    {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('production');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(false);

        $rows = $this->block->getChecklistRows();

        $this->assertTrue($rows[0]['ok']);
        $this->assertSame('PRODUCTION', $rows[1]['value']);
        $this->assertTrue($rows[2]['ok']);
        $this->assertFalse($this->block->isProductionWithSslDisabled());
    }

    public function testUnverifiedApiKeyRowIsNotOk(): void
    {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::INVALID_KEY]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(false);

        $rows = $this->block->getChecklistRows();

        $this->assertFalse($rows[0]['ok']);
    }

    public function testSslDisabledRowIsNotOk(): void
    {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $rows = $this->block->getChecklistRows();

        $this->assertFalse($rows[2]['ok']);
    }

    public function testProductionWithSslDisabledWarns(): void
    {
        $this->configRepository->method('getMode')->willReturn('production');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $this->assertTrue($this->block->isProductionWithSslDisabled());
    }

    public function testSandboxWithSslDisabledDoesNotWarn(): void
    {
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $this->assertFalse($this->block->isProductionWithSslDisabled());
    }
}

/**
 * Constructor-free subclass — the heavy Field base constructor needs a
 * full Magento admin Context, which this exercises no need for.
 */
class HealthChecklistTestable extends HealthChecklist
{
    public function __construct()
    {
    }

    public function setDependencies(ConfigRepository $configRepository, ApiKeyStatus $apiKeyStatus): void
    {
        $ref = new \ReflectionClass(HealthChecklist::class);

        $configProp = $ref->getProperty('configRepository');
        $configProp->setAccessible(true);
        $configProp->setValue($this, $configRepository);

        $apiKeyProp = $ref->getProperty('apiKeyStatus');
        $apiKeyProp->setAccessible(true);
        $apiKeyProp->setValue($this, $apiKeyStatus);
    }
}
