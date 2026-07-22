<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;

final class ScannerTest extends TestCase
{
    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, Finding> keyed by option key
     */
    private function optionsByKey(array $findings): array
    {
        $byKey = [];
        foreach ($findings as $finding) {
            if ($finding->type === 'option' && $finding->key !== null) {
                $byKey[$finding->key] = $finding;
            }
        }

        return $byKey;
    }

    public function test_it_detects_literal_option_writes_as_verified(): void
    {
        $result = (new Scanner())->scan($this->fixture('dirty-plugin'));
        $options = $this->optionsByKey($result['findings']);

        self::assertArrayHasKey('dirty_version', $options);
        self::assertSame(Finding::CONFIDENCE_VERIFIED, $options['dirty_version']->confidence);
        self::assertSame('add_site_option', $options['dirty_network_flag']->function);
    }

    public function test_it_reports_unresolvable_keys_as_dynamic(): void
    {
        $result = (new Scanner())->scan($this->fixture('dirty-plugin'));

        $dynamic = array_filter(
            $result['findings'],
            static fn (Finding $f): bool => $f->type === 'option' && $f->confidence === Finding::CONFIDENCE_DYNAMIC,
        );

        self::assertCount(1, $dynamic, 'the runtime-keyed update_option must degrade to dynamic, not be guessed');
    }

    public function test_it_resolves_keys_across_files_and_from_properties(): void
    {
        $result = (new Scanner())->scan($this->fixture('resolved-plugin'));
        $options = $this->optionsByKey($result['findings']);

        // Cross-file constant: define('RP_PREFIX', 'rp_') in constants.php.
        self::assertArrayHasKey('rp_version', $options);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $options['rp_version']->confidence);

        // Class property literal.
        self::assertArrayHasKey('rp_opt_settings', $options);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $options['rp_opt_settings']->confidence);

        // Partly dynamic -> pattern with a stable prefix.
        self::assertArrayHasKey('rp_*', $options);
        self::assertSame(Finding::CONFIDENCE_PATTERN, $options['rp_*']->confidence);
    }

    public function test_malformed_php_is_recorded_as_an_error_not_thrown(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fuzz');

        self::assertGreaterThanOrEqual(1, count($result['errors']));
    }

    public function test_it_excludes_dependency_and_test_directories(): void
    {
        // Scanning the repo root must not pick up vendor/ or tests/ fixtures.
        $repoRoot = dirname(__DIR__, 2);
        $result = (new Scanner())->scan($repoRoot);

        foreach ($result['files'] as $file) {
            self::assertStringNotContainsString('vendor', $file);
            self::assertStringNotContainsString(DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR, $file);
        }
    }
}
