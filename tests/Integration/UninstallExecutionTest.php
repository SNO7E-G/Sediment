<?php

declare(strict_types=1);

namespace Sediment\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The project's central claim, checked against a real WordPress database rather
 * than asserted: a generated `uninstall.php` removes exactly what the plugin
 * created, and nothing else.
 *
 * Unit tests can prove the generated file *says* the right thing. Only running
 * it against a live install proves it *does* the right thing — that
 * `delete_transient` also clears the timeout twin, that `remove_role` really
 * drops the role, and above all that no core option or table is caught in the
 * blast.
 *
 * Set SEDIMENT_WP_PATH to a configured WordPress directory to run it; the test
 * skips otherwise, so the unit suite stays runnable anywhere.
 */
#[Group('integration')]
final class UninstallExecutionTest extends TestCase
{
    public function test_a_generated_uninstall_removes_its_own_data_and_nothing_else(): void
    {
        $wordpress = getenv('SEDIMENT_WP_PATH');

        if ($wordpress === false || !is_file($wordpress . '/wp-load.php')) {
            self::markTestSkipped('Set SEDIMENT_WP_PATH to a configured WordPress install to run the live check.');
        }

        $result = $this->runCheck($wordpress, __DIR__ . '/fixtures/live-plugin');

        // The fixture ships no uninstall routine, so Sediment should say so.
        self::assertSame('F', $result['grade']);

        // It really did create data — otherwise the rest proves nothing.
        self::assertNotEmpty($result['created']['options'] ?? [], 'the fixture should have created options');
        self::assertContains('_transient_timeout_slf_cache', $result['created']['options'], 'a transient writes a timeout row too');
        self::assertNotEmpty($result['created']['tables'] ?? [], 'the fixture should have created a table');
        self::assertContains('slf_manager', $result['created']['roles'] ?? []);

        // Everything it created is gone.
        self::assertSame([], $result['leftover'], 'the generated uninstall left some of the plugin\'s own data behind');

        // And nothing else is.
        self::assertSame([], $result['collateral'], 'the generated uninstall removed data the plugin never created');

        // A WordPress install has far more than this; the point is that a full
        // core install is still standing afterwards.
        self::assertGreaterThan(50, $result['core_options_remaining']);
        self::assertGreaterThanOrEqual(12, $result['core_tables_remaining']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runCheck(string $wordpress, string $fixture): array
    {
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/run-uninstall-check.php'),
            escapeshellarg($wordpress),
            escapeshellarg($fixture),
        );

        $output = (string) shell_exec($command);
        $lastLine = trim((string) strrchr("\n" . trim($output), "\n"));
        $decoded = json_decode($lastLine, true);

        self::assertIsArray($decoded, "the live check did not return a result:\n" . $output);

        return $decoded;
    }
}
