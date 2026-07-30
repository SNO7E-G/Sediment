<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Scanner;
use Sediment\Command\DiffCommand;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;
use Sediment\Manifest\ManifestDiff;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `diff` exists to fail a build when a release makes the footprint worse, so its
 * verdict — and its exit code — are the contract.
 */
final class ManifestDiffTest extends TestCase
{
    /**
     * @param array<string, array{0: string, 1: bool}> $items group => [key, cleaned]
     * @return array<string, mixed>
     */
    private function manifest(string $grade, array $items): array
    {
        $creates = [];
        foreach ($items as $entry) {
            [$group, $key, $cleaned] = $entry;
            $creates[$group][] = ['key' => $key, 'cleaned' => $cleaned];
        }

        return ['grade' => $grade, 'creates' => $creates];
    }

    public function test_an_identical_scan_is_not_a_regression(): void
    {
        $m = $this->manifest('A', [['options', 'acme_settings', true]]);
        $diff = ManifestDiff::between($m, $m);

        self::assertFalse($diff['regressed']);
        self::assertSame([], $diff['added']);
    }

    public function test_a_new_uncleaned_artifact_is_a_regression(): void
    {
        $diff = ManifestDiff::between(
            $this->manifest('A', [['options', 'acme_settings', true]]),
            $this->manifest('D', [['options', 'acme_settings', true], ['options', 'acme_cache', false]]),
        );

        self::assertTrue($diff['regressed']);
        self::assertSame(['options:acme_cache'], $diff['added']);
    }

    public function test_a_new_artifact_that_is_cleaned_up_is_not_a_regression(): void
    {
        // Adding data is fine as long as the plugin removes it again.
        $diff = ManifestDiff::between(
            $this->manifest('A', [['options', 'acme_settings', true]]),
            $this->manifest('A', [['options', 'acme_settings', true], ['options', 'acme_new', true]]),
        );

        self::assertFalse($diff['regressed']);
        self::assertSame(['options:acme_new'], $diff['added']);
    }

    public function test_an_artifact_that_stopped_being_cleaned_is_a_regression(): void
    {
        $diff = ManifestDiff::between(
            $this->manifest('A', [['options', 'acme_settings', true]]),
            $this->manifest('A', [['options', 'acme_settings', false]]),
        );

        self::assertTrue($diff['regressed']);
        self::assertSame(['options:acme_settings'], $diff['no_longer_cleaned']);
    }

    public function test_a_worse_grade_is_a_regression_and_a_better_one_is_not(): void
    {
        $before = $this->manifest('B', [['options', 'k', true]]);

        self::assertTrue(ManifestDiff::between($before, $this->manifest('D', [['options', 'k', true]]))['regressed']);
        self::assertFalse(ManifestDiff::between($before, $this->manifest('A', [['options', 'k', true]]))['regressed']);
    }

    public function test_removing_an_artifact_is_reported_and_is_not_a_regression(): void
    {
        $diff = ManifestDiff::between(
            $this->manifest('D', [['options', 'k', false], ['tables', '{prefix}t', false]]),
            $this->manifest('C', [['options', 'k', false]]),
        );

        self::assertSame(['tables:{prefix}t'], $diff['removed']);
        self::assertFalse($diff['regressed']);
    }

    public function test_the_command_fails_when_the_footprint_regressed(): void
    {
        $baseline = tempnam(sys_get_temp_dir(), 'sediment') ?: throw new \RuntimeException('no temp file');

        try {
            // A baseline claiming the plugin cleaned everything up; the real
            // partial-plugin does not, so this must fail.
            file_put_contents($baseline, (string) json_encode($this->manifest('A', [
                ['options', 'pp_settings', true],
                ['options', 'pp_temp', true],
            ])));

            $tester = new CommandTester(new DiffCommand());
            $tester->execute([
                'baseline' => $baseline,
                'path' => dirname(__DIR__) . '/fixtures/partial-plugin',
            ]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('got worse', $tester->getDisplay());
        } finally {
            @unlink($baseline);
        }
    }

    public function test_the_command_passes_against_its_own_manifest(): void
    {
        $path = dirname(__DIR__) . '/fixtures/clean-plugin';
        $scan = (new Scanner())->scan($path);
        $manifest = Manifest::build($scan, (new Grader())->grade($scan['findings'], $scan['cleanup']), $path, '2026-07-26T00:00:00Z');

        $baseline = tempnam(sys_get_temp_dir(), 'sediment') ?: throw new \RuntimeException('no temp file');

        try {
            file_put_contents($baseline, (string) json_encode($manifest));

            $tester = new CommandTester(new DiffCommand());
            $tester->execute(['baseline' => $baseline, 'path' => $path]);

            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        } finally {
            @unlink($baseline);
        }
    }

    public function test_a_missing_or_invalid_baseline_fails_clearly(): void
    {
        $tester = new CommandTester(new DiffCommand());
        $tester->execute(['baseline' => '/does/not/exist.json', 'path' => dirname(__DIR__) . '/fixtures/clean-plugin']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }
}
