<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Scanner;

/**
 * A scan's memory use must stay proportional to the largest single file, not to
 * the whole tree. Holding every syntax tree between the two passes cost hundreds
 * of megabytes on a large plugin — enough to exceed PHP's default limit and end
 * a batch run on the first big plugin it met.
 */
final class ScanMemoryTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach ((array) glob($this->dir . '/*.php') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->dir);
        }
    }

    public function test_memory_does_not_grow_with_the_number_of_files(): void
    {
        $this->dir = sys_get_temp_dir() . '/sediment-mem-' . getmypid();
        @mkdir($this->dir, 0777, true);

        // Each file is substantial enough that holding all of their syntax trees
        // would be obvious in the measurement.
        $body = str_repeat("    add_option('mem_key_N_' . __LINE__, array(1, 2, 3));\n", 40);
        for ($i = 0; $i < 120; $i++) {
            file_put_contents(
                $this->dir . "/file{$i}.php",
                "<?php\nfunction mem_fn{$i}() {\n" . str_replace('_N_', "_{$i}_", $body) . "}\n",
            );
        }

        $before = memory_get_usage(true);
        $result = (new Scanner())->scan($this->dir);
        $used = memory_get_usage(true) - $before;

        self::assertCount(120, $result['files']);
        self::assertNotEmpty($result['findings']);

        // Measured on this corpus: re-reading retains about 6 MB (nearly all of it
        // the findings themselves), while holding every tree costs about 36 MB.
        // The ceiling sits between the two with room on both sides, so it fails
        // if trees are retained again without being flaky in normal operation.
        self::assertLessThan(
            16 * 1024 * 1024,
            $used,
            sprintf('a scan retained %.1f MB, which suggests syntax trees are being held', $used / 1048576),
        );
    }
}
