<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Command\CheckCommand;
use Symfony\Component\Console\Command\Command;
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

    public function test_a_missing_path_fails(): void
    {
        self::assertSame(Command::FAILURE, $this->check('does-not-exist')->getStatusCode());
    }
}
