<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The merchant's custom headers are attached in exactly one place —
 * Service\Api\Adapter. A call site that builds its own HTTP client silently
 * skips them and is blocked by the very firewall the table exists to clear,
 * so a new one has to be a deliberate act rather than an oversight.
 */
class OutboundCallSiteCoverageTest extends TestCase
{
    /**
     * Service\Invoice\UploadService PUTs the invoice bytes to a signed
     * Cloud Storage URL, whose headers are dictated by the signature the API
     * returned; steps 1 and 3 of that flow go through the Adapter.
     */
    private const CLIENTS_OUTSIDE_THE_ADAPTER = [
        'Service/Api/Adapter.php',
        'Service/Invoice/UploadService.php',
    ];

    private const CLIENT_MARKERS = [
        'CurlFactory',
        'curl_init',
        'curl_exec',
        'GuzzleHttp',
        'Laminas\\Http',
        'Zend\\Http',
    ];

    /**
     * Given every production PHP file; When one constructs an HTTP client of
     * its own; Then it is a named exception, not a new unheadered call site.
     */
    public function testNoProductionClassBuildsAnHttpClientOutsideTheAdapter(): void
    {
        $found = [];
        foreach ($this->productionSources() as $relative => $absolute) {
            $source = (string)file_get_contents($absolute);
            foreach (self::CLIENT_MARKERS as $marker) {
                if (strpos($source, $marker) !== false) {
                    $found[] = $relative;
                    break;
                }
            }
        }
        sort($found);
        $expected = self::CLIENTS_OUTSIDE_THE_ADAPTER;
        sort($expected);

        $this->assertSame(
            $expected,
            $found,
            'a call site outside Service\Api\Adapter sends none of the merchant\'s configured headers'
        );
    }

    /**
     * Given the sole place headers are attached; When the audit runs; Then the
     * scan is looking at real files rather than passing on an empty sweep.
     */
    public function testTheScanActuallyReachesTheModuleSources(): void
    {
        $sources = $this->productionSources();

        $this->assertGreaterThan(100, count($sources), 'the sweep found the module tree');
        $this->assertArrayHasKey('Service/Api/Adapter.php', $sources, 'the adapter itself is in scope');
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function productionSources(): array
    {
        $root = dirname(__DIR__, 4);
        $skipped = ['Test', 'dev', 'e2e', 'vendor', 'node_modules', '.worktrees', '.git'];

        $sources = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $absolute = (string)$file;
            $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
            if (substr($relative, -4) !== '.php') {
                continue;
            }
            $top = explode('/', $relative)[0];
            if (in_array($top, $skipped, true)) {
                continue;
            }
            $sources[$relative] = $absolute;
        }

        return $sources;
    }
}
