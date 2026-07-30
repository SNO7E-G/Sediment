<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Command\BatchCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BatchCommandTest extends TestCase
{
    private string $out = '';

    protected function tearDown(): void
    {
        if ($this->out !== '' && is_dir($this->out)) {
            foreach ((array) glob($this->out . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->out);
        }
    }

    public function test_it_writes_one_manifest_per_plugin_and_summarises_grades(): void
    {
        $this->out = sys_get_temp_dir() . '/sediment-batch-' . getmypid();

        $tester = new CommandTester(new BatchCommand());
        $tester->execute([
            'directory' => dirname(__DIR__) . '/fixtures',
            '--out' => $this->out,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        // Every fixture directory is a "plugin" here, so each gets a manifest.
        $written = (array) glob($this->out . '/*.json');
        self::assertGreaterThan(10, count($written));

        $clean = json_decode((string) file_get_contents($this->out . '/clean-plugin.json'), true);
        self::assertSame('A', $clean['grade']);
        self::assertSame('clean-plugin', $clean['plugin']['slug']);

        $partial = json_decode((string) file_get_contents($this->out . '/partial-plugin.json'), true);
        self::assertSame('D', $partial['grade']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Overall resolution rate', $display);
        self::assertStringContainsString('grade', $display);
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
}
