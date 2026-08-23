<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Command\BatchCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The batch output directory is a shared resource: two runs writing it at once
 * would interleave manifests and poison --resume. An advisory lock refuses the
 * second run instead of letting them fight.
 */
final class BatchLockTest extends TestCase
{
    public function test_a_second_batch_over_the_same_output_directory_is_refused(): void
    {
        $root = sys_get_temp_dir() . '/sediment-batch-lock-' . getmypid();
        @mkdir($root . '/plugins/clean-plugin', 0777, true);
        @mkdir($root . '/out');
        copy(dirname(__DIR__) . '/fixtures/clean-plugin/clean-plugin.php', $root . '/plugins/clean-plugin/clean-plugin.php');

        $out = $root . '/out';
        $lock = fopen($out . '/.sediment-batch.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'test setup: could not hold the lock');

        try {
            $command = new BatchCommand();
            $tester = new CommandTester($command);
            $status = $tester->execute([
                'directory' => $root . '/plugins',
                '--out' => $out,
                '--report' => $root . '/report.json',
            ]);

            self::assertSame(Command::FAILURE, $status, 'a held lock must refuse a second batch');
            self::assertSame(
                [],
                glob($out . '/*.json') ?: [],
                'the refused run must not have written manifests',
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($root . '/plugins/clean-plugin/clean-plugin.php');
            @rmdir($root . '/plugins/clean-plugin');
            @rmdir($root . '/plugins');
            @unlink($out . '/.sediment-batch.lock');
            @rmdir($out);
            @rmdir($root);
        }
    }
}
