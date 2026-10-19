<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\FileWalker;

final class FileWalkerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/sediment-walker-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    private function put(string $relative): void
    {
        @mkdir(dirname($this->root . '/' . $relative), 0777, true);
        file_put_contents($this->root . '/' . $relative, "<?php\n");
    }

    /** @return list<string> */
    private function walk(): array
    {
        return array_map(
            fn (string $file): string => substr($file, strlen($this->root) + 1),
            (new FileWalker())->walk($this->root),
        );
    }

    public function test_excluded_directories_match_regardless_of_case(): void
    {
        $this->put('plugin.php');
        $this->put('Tests/FooTest.php');
        $this->put('lib/Vendor/dep.php');
        $this->put('Dist/x.php');

        self::assertSame(['plugin.php'], $this->walk());
    }

    public function test_a_block_render_template_in_build_is_read(): void
    {
        $this->put('plugin.php');
        $this->put('build/my-block/render.php');
        $this->put('build/my-block/index.asset.php');
        $this->put('build/vendor/render.php');

        self::assertSame(['build/my-block/render.php', 'plugin.php'], $this->walk());
    }

    public function test_symlinked_php_files_are_not_read(): void
    {
        $this->put('plugin.php');
        $outside = $this->root . '-outside.php';
        file_put_contents($outside, "<?php\n");

        try {
            if (!@symlink($outside, $this->root . '/linked.php')) {
                self::markTestSkipped('symlinks cannot be created here');
            }

            self::assertSame(['plugin.php'], $this->walk());
        } finally {
            @unlink($outside);
        }
    }
}
