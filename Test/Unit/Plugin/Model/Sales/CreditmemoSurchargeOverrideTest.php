<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Model\Sales;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Plugin\Model\Sales\CreditmemoSurchargeOverride;

class CreditmemoSurchargeOverrideTest extends TestCase
{
    private function request(array $params, string $action = 'save')
    {
        return new class ($params, $action) implements \Magento\Framework\App\RequestInterface {
            private $p;
            private $a;
            public function __construct($p, $a)
            {
                $this->p = $p;
                $this->a = $a;
            }
            public function getParam($key, $default = null)
            {
                return $this->p[$key] ?? $default;
            }
            public function getControllerName()
            {
                return 'order_creditmemo';
            }
            public function getActionName()
            {
                return $this->a;
            }
        };
    }

    private function format()
    {
        return new class implements \Magento\Framework\Locale\FormatInterface {
            public function getNumber($value)
            {
                return (float)str_replace(',', '.', (string)$value);
            }
            public function getPriceFormat($localeCode = null, $currencyCode = null)
            {
                return [];
            }
        };
    }

    private function order(float $amount, float $refunded = 0.0): Order
    {
        $o = new Order();
        $o->setData('two_surcharge_amount', $amount);
        $o->setData('two_surcharge_refunded', $refunded);
        return $o;
    }

    public function testBlankIsTreatedAsExplicitZero(): void
    {
        $plugin = new CreditmemoSurchargeOverride(
            $this->request(['creditmemo' => ['two_surcharge_amount' => '']]),
            $this->format()
        );
        $cm = new Creditmemo();
        $cm->setOrder($this->order(5.0));

        $plugin->beforeCollectTotals($cm);

        $this->assertSame(
            0.0,
            (float)$cm->getData('two_surcharge_amount'),
            'a cleared field must become an explicit 0 override, not fall back to the default'
        );
    }

    public function testValidLocaleValueIsStamped(): void
    {
        $plugin = new CreditmemoSurchargeOverride(
            $this->request(['creditmemo' => ['two_surcharge_amount' => '2,50']]),
            $this->format()
        );
        $cm = new Creditmemo();
        $cm->setOrder($this->order(5.0));

        $plugin->beforeCollectTotals($cm);

        $this->assertEqualsWithDelta(2.5, (float)$cm->getData('two_surcharge_amount'), 0.0001);
    }

    public function testExceedsGuardMessageIsBrandNeutral(): void
    {
        $plugin = new CreditmemoSurchargeOverride(
            $this->request(['creditmemo' => ['two_surcharge_amount' => '999']]),
            $this->format()
        );
        $cm = new Creditmemo();
        $cm->setOrder($this->order(5.0));

        try {
            $plugin->beforeCollectTotals($cm);
            $this->fail('expected a LocalizedException for an over-max surcharge refund');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->assertStringContainsString('Surcharge refund (', $e->getMessage());
            $this->assertStringNotContainsString('Two', $e->getMessage());
        }
    }
}
