<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every Two\Gateway class named in etc/di.xml must exist on disk.
 *
 * A stale reference is invisible to both PHPUnit (no ObjectManager) and
 * `setup:di:compile` (it validates the XML, never resolves an <item>'s
 * class), and only surfaces at runtime as a ReflectionException that takes
 * down every consumer of the wired type — checkout included.
 */
class DiConfigClassReferencesTest extends TestCase
{
    /**
     * @dataProvider referencedClasses
     */
    public function testReferencedClassExists(string $class, string $file, string $description): void
    {
        $this->assertFileExists($file, sprintf('%s: etc/di.xml references %s', $description, $class));
    }

    public static function referencedClasses(): array
    {
        $root = dirname(__DIR__, 2);
        preg_match_all(
            '/Two\\\\Gateway\\\\[A-Za-z0-9_\\\\]+/',
            file_get_contents($root . '/etc/di.xml'),
            $matches
        );

        $cases = [];
        foreach ($matches[0] as $class) {
            // \Proxy and \Interceptor are code-generated, never on disk.
            $onDisk = preg_replace('/\\\\(Proxy|Interceptor)$/', '', $class);
            $cases[$onDisk] = [
                $class,
                $root . '/' . str_replace('\\', '/', substr($onDisk, strlen('Two\\Gateway\\'))) . '.php',
                'di.xml names a class that must exist',
            ];
        }

        return array_values($cases);
    }
}
