<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\OrderPostprocessingInterface;
use Two\Gateway\Model\OrderPostprocessing;

/**
 * Static guard for the order postprocessing contract (TWO-26092): a send to
 * an order endpoint that bypasses the hook, or a lost default binding, goes
 * red here even where no behavioural test reaches the path.
 */
class OrderPostprocessingAllowlistTest extends TestCase
{
    private const PROCESS = '$this->orderPostprocessor->process(';

    /**
     * Every POST or PUT to an order endpoint takes its body straight from the
     * postprocessor, with the request type its endpoint implies.
     */
    public function testEveryOrderSendGoesThroughThePostprocessor(): void
    {
        $sends = $this->orderSends();

        $this->assertCount(10, $sends, 'a send was added or removed: extend sendSites() in the send-sites test too');
        foreach ($sends as [$where, $endpoint, $body]) {
            $this->assertStringStartsWith(self::PROCESS, $body, $where . ' sends without the hook');
            $this->assertStringContainsString(
                'Postprocessing::' . $this->expectedType($endpoint) . ',',
                str_replace('OrderPostprocessingInterface::', 'Postprocessing::', $body),
                $where . ' fires the wrong request type for ' . $endpoint
            );
        }
    }

    /**
     * @dataProvider bindings
     */
    public function testTheDefaultBindingsStay(string $interface, string $type, string $description): void
    {
        $di = (string)file_get_contents(dirname(__DIR__, 4) . '/etc/di.xml');

        $this->assertMatchesRegularExpression(
            '#<preference\s+for="' . preg_quote($interface, '#') . '"\s+type="' . preg_quote($type, '#') . '"\s*/>#',
            $di,
            $description
        );
    }

    public static function bindings(): array
    {
        return [
            ['Two\Gateway\Api\OrderPostprocessingInterface', 'Two\Gateway\Model\OrderPostprocessing', 'the hook binds to the identity default'],
            ['Two\Gateway\Api\OrderPostprocessingTotalsInterface', 'Two\Gateway\Service\Order\PostprocessingTotals', 'the opt-in totals helper'],
        ];
    }

    public function testTheDefaultReturnsThePayloadUnchanged(): void
    {
        $payload = ['gross_amount' => '1.00', 'line_items' => [['net_amount' => '1.00']]];

        $this->assertSame($payload, (new OrderPostprocessing())->process($payload, []));
        $this->assertSame(1, OrderPostprocessingInterface::CONTRACT_VERSION);
    }

    private function expectedType(string $endpoint): string
    {
        foreach ([
            'ENDPOINT' => 'REQUEST_ORDER_INTENT',
            '/cancel' => 'REQUEST_CANCEL',
            '/confirm' => 'REQUEST_ORDER_CONFIRM',
            '/fulfillments' => 'REQUEST_CAPTURE',
            '/refund' => 'REQUEST_REFUND',
            'getTwoOrderId()' => 'REQUEST_ORDER_UPDATE',
        ] as $marker => $type) {
            if (str_contains($endpoint, $marker)) {
                return $type;
            }
        }

        return 'REQUEST_ORDER_CREATE';
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}> [file:call, endpoint arg, body arg]
     */
    private function orderSends(): array
    {
        $root = dirname(__DIR__, 4);
        $sends = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(Test|vendor|node_modules|\.worktrees|e2e)/#', $path)) {
                continue;
            }
            $src = (string)file_get_contents($file->getPathname());
            preg_match_all('/->(?:execute|executeWithStatus)\(/', $src, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as [$match, $offset]) {
                $args = $this->arguments($src, $offset + strlen($match));
                $endpoint = $args[0] ?? '';
                $isOrder = str_contains($endpoint, '/v1/order') || $endpoint === 'self::ENDPOINT' && str_contains($path, 'OrderIntent');
                if (!$isOrder || trim($args[2] ?? "'POST'", '\'"') === 'GET') {
                    continue;
                }
                $sends[] = [$path . ':' . substr_count(substr($src, 0, $offset), "\n"), $endpoint, $args[1] ?? ''];
            }
        }

        return $sends;
    }

    /**
     * Top-level arguments of the call whose opening parenthesis ends at $start.
     *
     * @return string[]
     */
    private function arguments(string $src, int $start): array
    {
        $args = [];
        $depth = 0;
        $current = '';
        $quote = null;
        for ($i = $start, $n = strlen($src); $i < $n; $i++) {
            $char = $src[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\') {
                    $current .= $src[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                if ($depth === 0) {
                    $args[] = trim($current);
                    return $args;
                }
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }

        return $args;
    }
}
