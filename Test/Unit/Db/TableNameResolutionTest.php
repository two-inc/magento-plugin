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
     * Directories that hold no module source. `.worktrees` is pruned rather
     * than filtered because it holds whole sibling checkouts.
     */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.worktrees', '.git', 'Test', 'e2e'];

    /**
     * @return array<string, string> repo-relative path => source
     */
    private function modulePhpFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $directories = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $pruned = new \RecursiveCallbackFilterIterator(
            $directories,
            static function (\SplFileInfo $file): bool {
                return !$file->isDir() || !in_array($file->getFilename(), self::SKIP_DIRS, true);
            }
        );

        $files = [];
        foreach (new \RecursiveIteratorIterator($pruned) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace($root . '/', '', $file->getPathname());
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
