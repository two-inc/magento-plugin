<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * Only ResourceConnection::getTableName() applies the installation's table
 * prefix; the adapter's method of the same name just shortens an over-long
 * name. Asking the adapter addresses a table that does not exist on a prefixed
 * install, and no unprefixed development install ever notices.
 */
class TableNameResolutionTest extends TestCase
{
    public function testNoQueryResolvesATableNameThroughTheConnection(): void
    {
        $offenders = [];
        foreach ($this->modulePhpFiles() as $relative => $source) {
            foreach (preg_split('/\R/', $source) as $index => $line) {
                if (preg_match('/->getConnection\(\)->getTableName\(/', $line)
                    || preg_match('/\$(?:conn|connection|adapter)\w*->getTableName\(/', $line)
                ) {
                    $offenders[] = sprintf('%s:%d', $relative, $index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            sprintf(
                "%d query resolves a table name through the connection, which skips the"
                . " installation's table prefix — use the injected ResourceConnection:\n  %s",
                count($offenders),
                implode("\n  ", $offenders)
            )
        );
    }

    /**
     * @return array<string, string> repo-relative path => source
     */
    private function modulePhpFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relative = str_replace($root . '/', '', $file->getPathname());
            if ($file->getExtension() !== 'php'
                || str_starts_with($relative, 'vendor/')
                || str_starts_with($relative, 'node_modules/')
                || str_starts_with($relative, '.worktrees/')
                || str_starts_with($relative, 'Test/')
            ) {
                continue;
            }
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        $this->assertGreaterThan(
            100,
            count($files),
            sprintf('Scanned only %d PHP files — the walk is broken.', count($files))
        );

        return $files;
    }
}
