<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\FileWalker;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;

/**
 * The same plugin must produce the same manifest — on any machine.
 *
 * A manifest is a published document that gets diffed, committed as a golden
 * expectation, and consumed by the Index. If its contents depend on the order a
 * filesystem hands files over, then `diff` reports changes that never happened
 * and a corpus fails on one platform while passing on another. Which is exactly
 * what happened: `\` and `/` sort differently against the characters between
 * them, so `admin/x.php` and `admin\x.php` land on opposite sides of
 * `adminA.php`, and the same plugin scanned in a different order on Linux than
 * on Windows.
 */
final class DeterminismTest extends TestCase
{
    public function test_the_walker_orders_files_the_same_way_on_any_platform(): void
    {
        $files = (new FileWalker())->walk(dirname(__DIR__) . '/fixtures');

        self::assertNotEmpty($files);

        foreach ($files as $file) {
            self::assertStringNotContainsString(
                '\\',
                $file,
                'paths must be separator-normalised before sorting, or scan order becomes platform-dependent',
            );
        }

        $sorted = $files;
        sort($sorted);
        self::assertSame($sorted, $files, 'the walker should return files already in a stable order');
    }

    public function test_scanning_the_same_plugin_twice_produces_an_identical_manifest(): void
    {
        $path = dirname(__DIR__) . '/fixtures/partial-plugin';

        self::assertSame($this->manifest($path), $this->manifest($path));
    }

    public function test_artifacts_are_ordered_by_key_not_by_the_order_files_were_read(): void
    {
        // woocommerce alone contributes hundreds of artifacts; if their order
        // tracked scan order, its manifest would differ between platforms.
        $creates = $this->manifest(dirname(__DIR__) . '/fixtures/dirty-plugin')['creates'];

        foreach ($creates as $group => $items) {
            $keys = array_column($items, 'key');
            $sorted = $keys;
            sort($sorted);

            self::assertSame($sorted, $keys, "{$group} should be ordered by key");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $path): array
    {
        $scan = (new Scanner())->scan($path);

        return Manifest::build(
            $scan,
            (new Grader())->grade($scan['findings'], $scan['cleanup']),
            $path,
            '1970-01-01T00:00:00Z',
        );
    }
}
