<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Application;
use Sediment\Command\CheckCommand;
use Sediment\Command\FetchCommand;
use Sediment\Command\ScanCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `check` exists to gate a build, so its exit code is the contract — not its
 * output.
 */
final class CheckCommandTest extends TestCase
{
    private function check(string $fixture, ?string $failOn = null): CommandTester
    {
        $tester = new CommandTester(new CheckCommand());
        $input = ['path' => dirname(__DIR__) . '/fixtures/' . $fixture];
        if ($failOn !== null) {
            $input['--fail-on'] = $failOn;
        }
        $tester->execute($input);

        return $tester;
    }

    public function test_it_passes_when_the_grade_meets_the_threshold(): void
    {
        // clean-plugin grades A.
        self::assertSame(Command::SUCCESS, $this->check('clean-plugin', 'C')->getStatusCode());
    }

    public function test_it_fails_when_the_grade_is_worse_than_the_threshold(): void
    {
        // partial-plugin grades D.
        $tester = $this->check('partial-plugin', 'C');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('worse than', $tester->getDisplay());
    }

    public function test_the_threshold_is_inclusive(): void
    {
        // D is not worse than D, so --fail-on=D passes a D plugin.
        self::assertSame(Command::SUCCESS, $this->check('partial-plugin', 'D')->getStatusCode());
    }

    public function test_it_defaults_to_failing_only_below_D(): void
    {
        self::assertSame(Command::SUCCESS, $this->check('partial-plugin')->getStatusCode());
        // dirty-plugin has no uninstall routine at all, so it grades F.
        self::assertSame(Command::FAILURE, $this->check('dirty-plugin')->getStatusCode());
    }

    public function test_an_invalid_threshold_is_rejected_rather_than_ignored(): void
    {
        $tester = $this->check('clean-plugin', 'Z');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Invalid --fail-on', $tester->getDisplay());
    }

    public function test_a_usage_error_exits_2_so_ci_can_tell_it_from_a_failed_gate(): void
    {
        // A path that is not there, on stderr, with nothing on stdout.
        $tester = new CommandTester(new CheckCommand());
        $tester->execute(['path' => dirname(__DIR__) . '/fixtures/does-not-exist'], ['capture_stderr_separately' => true]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Path not found', $tester->getErrorOutput());

        // A command line Symfony cannot parse exits 2 as well, not 1.
        $app = new Application();
        $app->setAutoExit(false);
        $console = new ApplicationTester($app);
        self::assertSame(Command::INVALID, $console->run(['command' => 'check']));
        self::assertSame(Command::INVALID, $console->run(['command' => 'check', 'path' => '.', '--bogus' => true]));
    }

    public function test_scan_and_fetch_errors_go_to_stderr(): void
    {
        // `scan --json > manifest.json` and `fetch --quiet-path` are read by
        // scripts; an error on stdout would be taken for the output.
        $scan = new CommandTester(new ScanCommand());
        $scan->execute(['path' => '/does/not/exist', '--json' => true], ['capture_stderr_separately' => true]);
        self::assertSame('', $scan->getDisplay());
        self::assertStringContainsString('Path not found', $scan->getErrorOutput());

        $fetch = new CommandTester(new FetchCommand());
        $fetch->execute(['slug' => "akismet\n", '--quiet-path' => true], ['capture_stderr_separately' => true]);
        self::assertSame(Command::FAILURE, $fetch->getStatusCode());
        self::assertSame('', $fetch->getDisplay());
        self::assertStringContainsString('not a valid plugin slug', $fetch->getErrorOutput());
    }

    public function test_min_coverage_fails_a_plugin_whose_writes_cannot_be_read(): void
    {
        // Every key is built at runtime, so there is nothing confident to grade
        // and the letter is an empty A. --min-coverage is how a gate refuses that.
        $dir = sys_get_temp_dir() . '/sediment-coverage-' . getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/plugin.php', "<?php\nupdate_option(\$_GET['k'], 1);\nset_transient(get_key(), 1);\n");

        try {
            $run = function (array $options) use ($dir): int {
                $tester = new CommandTester(new CheckCommand());
                $tester->execute(['path' => $dir, '--fail-on' => 'A'] + $options);

                return $tester->getStatusCode();
            };

            self::assertSame(Command::SUCCESS, $run([]), 'the letter alone passes');
            self::assertSame(Command::FAILURE, $run(['--min-coverage' => '50']));
            self::assertSame(Command::SUCCESS, $run(['--min-coverage' => '0']));
            self::assertSame(Command::INVALID, $run(['--min-coverage' => 'lots']));
        } finally {
            @unlink($dir . '/plugin.php');
            @rmdir($dir);
        }
    }
}
