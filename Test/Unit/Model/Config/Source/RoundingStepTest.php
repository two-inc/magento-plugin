<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Source\RoundingStep;

class RoundingStepTest extends TestCase
{
    public function testOptionsReflectBrandStepsInCanonicalTwoDecimalForm(): void
    {
        $source = new RoundingStep($this->registryReturning([0.1, 0.5, 1.0, 5.0, 10.0]));

        $this->assertSame(
            [
                ['value' => '0.10', 'label' => '0.10'],
                ['value' => '0.50', 'label' => '0.50'],
                ['value' => '1.00', 'label' => '1.00'],
                ['value' => '5.00', 'label' => '5.00'],
                ['value' => '10.00', 'label' => '10.00'],
            ],
            $source->toOptionArray()
        );
    }

    public function testNarrowedBrandSetProducesOnlyThoseOptions(): void
    {
        $source = new RoundingStep($this->registryReturning([0.5, 1.0]));

        $this->assertSame(
            [
                ['value' => '0.50', 'label' => '0.50'],
                ['value' => '1.00', 'label' => '1.00'],
            ],
            $source->toOptionArray()
        );
    }

    public function testEmptyStepSetProducesNoOptions(): void
    {
        $source = new RoundingStep($this->registryReturning([]));

        $this->assertSame([], $source->toOptionArray());
    }

    /**
     * @param float[] $steps
     */
    private function registryReturning(array $steps): BrandRegistryInterface
    {
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('getSurchargeRoundingSteps')->willReturn($steps);
        return $registry;
    }
}
