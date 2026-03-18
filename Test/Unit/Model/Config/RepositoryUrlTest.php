<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Repository;

/**
 * Tests for URL generation in Config\Repository:
 * getCheckoutApiUrl() and getCheckoutPageUrl().
 */
class RepositoryUrlTest extends TestCase
{
    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var State|\PHPUnit\Framework\MockObject\MockObject */
    private $appState;

    /** @var Repository */
    private $repository;

    /** @var string[] env vars to clean up */
    private $envVarsToClear = [];

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $urlBuilder = $this->createMock(UrlInterface::class);
        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $this->appState = $this->createMock(State::class);

        $this->repository = new Repository(
            $this->scopeConfig,
            $encryptor,
            $urlBuilder,
            $productMetadata,
            $this->appState
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->envVarsToClear as $var) {
            putenv($var);
        }
        $this->envVarsToClear = [];
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("$name=$value");
        $this->envVarsToClear[] = $name;
    }

    private function configureMode(string $mode): void
    {
        $this->scopeConfig
            ->method('getValue')
            ->willReturn($mode);
    }

    // ── getCheckoutApiUrl ───────────────────────────────────────────────

    public function testApiUrlProductionMode(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $this->configureMode('production');

        $this->assertEquals('https://api.two.inc', $this->repository->getCheckoutApiUrl());
    }

    public function testApiUrlSandboxMode(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $this->configureMode('sandbox');

        $this->assertEquals('https://api.sandbox.two.inc', $this->repository->getCheckoutApiUrl());
    }

    public function testApiUrlDeveloperModeWithEnvVar(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $this->setEnv('TWO_API_BASE_URL', 'http://localhost:8000');

        $this->assertEquals('http://localhost:8000', $this->repository->getCheckoutApiUrl());
    }

    public function testApiUrlDeveloperModeEmptyEnvVar(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $this->setEnv('TWO_API_BASE_URL', '');
        $this->configureMode('sandbox');

        $this->assertEquals('https://api.sandbox.two.inc', $this->repository->getCheckoutApiUrl());
    }

    public function testApiUrlNonDeveloperModeIgnoresEnvVar(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $this->setEnv('TWO_API_BASE_URL', 'http://localhost:8000');
        $this->configureMode('production');

        $this->assertEquals('https://api.two.inc', $this->repository->getCheckoutApiUrl());
    }

    public function testApiUrlExplicitModeParameter(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_PRODUCTION);

        $this->assertEquals(
            'https://api.staging.two.inc',
            $this->repository->getCheckoutApiUrl('staging')
        );
    }

    public function testApiUrlDeveloperModeEnvVarOverridesExplicitMode(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $this->setEnv('TWO_API_BASE_URL', 'http://localhost:8000');

        // Env var takes precedence over explicit $mode in developer mode
        $this->assertEquals('http://localhost:8000', $this->repository->getCheckoutApiUrl('sandbox'));
    }

    // ── getCheckoutPageUrl ──────────────────────────────────────────────

    public function testPageUrlProductionMode(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $this->configureMode('production');

        $this->assertEquals('https://checkout.two.inc', $this->repository->getCheckoutPageUrl());
    }

    public function testPageUrlSandboxMode(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $this->configureMode('sandbox');

        $this->assertEquals('https://checkout.sandbox.two.inc', $this->repository->getCheckoutPageUrl());
    }

    public function testPageUrlDeveloperModeWithEnvVar(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $this->setEnv('TWO_CHECKOUT_BASE_URL', 'http://localhost:3000');

        $this->assertEquals('http://localhost:3000', $this->repository->getCheckoutPageUrl());
    }

    public function testPageUrlDeveloperModeEmptyEnvVar(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $this->setEnv('TWO_CHECKOUT_BASE_URL', '');
        $this->configureMode('sandbox');

        $this->assertEquals('https://checkout.sandbox.two.inc', $this->repository->getCheckoutPageUrl());
    }

    public function testPageUrlNonDeveloperModeIgnoresEnvVar(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $this->setEnv('TWO_CHECKOUT_BASE_URL', 'http://localhost:3000');
        $this->configureMode('production');

        $this->assertEquals('https://checkout.two.inc', $this->repository->getCheckoutPageUrl());
    }

    public function testPageUrlExplicitModeParameter(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_PRODUCTION);

        $this->assertEquals(
            'https://checkout.staging.two.inc',
            $this->repository->getCheckoutPageUrl('staging')
        );
    }

    public function testPageUrlDeveloperModeEnvVarOverridesExplicitMode(): void
    {
        $this->appState->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $this->setEnv('TWO_CHECKOUT_BASE_URL', 'http://localhost:3000');

        // Env var takes precedence over explicit $mode in developer mode
        $this->assertEquals('http://localhost:3000', $this->repository->getCheckoutPageUrl('sandbox'));
    }
}
