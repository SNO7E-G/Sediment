<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Command\BatchCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Batch spawns one child PHP process per plugin, so every plugin here costs a
 * full interpreter start. The batches are kept to two plugins each — enough to
 * prove the plumbing, the grading summary, and that one failure stays one
 * failure, without turning the suite into a process-spawning benchmark on a
 * machine where PHP boots slowly.
 */
final class BatchCommandTest extends TestCase
{
    private string $out = '';
    private string $plugins = '';

    protected function tearDown(): void
    {
        foreach ([$this->out, $this->plugins] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                $this->removeDir($dir);
            }
        }
    }

    public function test_it_writes_one_manifest_per_plugin_and_summarises_grades(): void
    {
        $this->plugins = sys_get_temp_dir() . '/sediment-batch-plugins-' . getmypid();
        $this->out = sys_get_temp_dir() . '/sediment-batch-' . getmypid();

        @mkdir($this->plugins . '/clean-plugin', 0777, true);
        @mkdir($this->plugins . '/messy-plugin', 0777, true);
        file_put_contents(
            $this->plugins . '/clean-plugin/clean-plugin.php',
            "<?php\n/*\nPlugin Name: Clean\n*/\nupdate_option('clean_opt', 1, false);\n",
        );
        file_put_contents($this->plugins . '/clean-plugin/uninstall.php', "<?php\ndelete_option('clean_opt');\n");
        file_put_contents(
            $this->plugins . '/messy-plugin/messy-plugin.php',
            "<?php\nupdate_option('messy_opt', 1);\n",
        );

        $tester = new CommandTester(new BatchCommand());
        $tester->execute([
            'directory' => $this->plugins,
            '--out' => $this->out,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $clean = json_decode((string) file_get_contents($this->out . '/clean-plugin.json'), true);
        self::assertSame('A', $clean['grade']);
        self::assertSame('clean-plugin', $clean['plugin']['slug']);

        $messy = json_decode((string) file_get_contents($this->out . '/messy-plugin.json'), true);
        self::assertSame('F', $messy['grade']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Scanned 2 plugin(s)', $display);
        self::assertStringContainsString('Overall resolution rate', $display);
        self::assertStringContainsString('grade', $display);
    }

    public function test_a_plugin_that_exhausts_its_memory_cap_fails_alone_and_is_reported(): void
    {
        // One pathological plugin must cost one manifest, not the run. The cap
        // is enforced by the child's own PHP, so the parent survives to record
        // the failure with a reason a machine can read.
        $this->plugins = sys_get_temp_dir() . '/sediment-hog-' . getmypid();
        $this->out = sys_get_temp_dir() . '/sediment-hog-out-' . getmypid();

        @mkdir($this->plugins . '/hog', 0777, true);
        @mkdir($this->plugins . '/tiny', 0777, true);
        file_put_contents(
            $this->plugins . '/hog/hog.php',
            "<?php\n\$a = 'x'" . str_repeat(" . 'x'", 400000) . ";\n",
        );
        file_put_contents($this->plugins . '/tiny/tiny.php', "<?php\nupdate_option('tiny_option', 1);\n");

        $report = $this->out . '/report.json';

        $tester = new CommandTester(new BatchCommand());
        $tester->execute([
            'directory' => $this->plugins,
            '--out' => $this->out,
            '--report' => $report,
            '--memory-limit' => '32M',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->out . '/hog.json');
        self::assertFileExists($this->out . '/tiny.json');

        $summary = json_decode((string) file_get_contents($report), true);
        self::assertSame('error', $summary['failed']['hog']['reason']);
        self::assertSame(1, $summary['scanned']);
    }

    public function test_a_missing_directory_fails(): void
    {
        $tester = new CommandTester(new BatchCommand());
        $tester->execute(['directory' => '/does/not/exist']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function test_a_directory_with_no_plugins_fails_rather_than_reporting_success(): void
    {
        $empty = sys_get_temp_dir() . '/sediment-empty-' . getmypid();
        @mkdir($empty, 0777, true);

        try {
            $tester = new CommandTester(new BatchCommand());
            $tester->execute(['directory' => $empty]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
        } finally {
            @rmdir($empty);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $path) {
            is_dir((string) $path) ? $this->removeDir((string) $path) : @unlink((string) $path);
        }
        @rmdir($dir);
    }
}
