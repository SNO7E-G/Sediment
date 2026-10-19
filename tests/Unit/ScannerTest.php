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

    public function test_an_oversized_file_is_recorded_as_an_error_and_skipped(): void
    {
        // One pathological blob must not end a scan — or a batch child — so it
        // is recorded as an error entry and everything else still scans.
        $root = sys_get_temp_dir() . '/sediment-size-test-' . getmypid();
        @mkdir($root);
        file_put_contents($root . '/plugin.php', "<?php\nadd_option('normal_key', '1');\n");
        file_put_contents($root . '/huge.php', '<?php /* ' . str_repeat('x', Scanner::MAX_FILE_BYTES + 1024) . " */\n");

        try {
            $result = (new Scanner())->scan($root);

            $options = $this->optionsByKey($result['findings']);
            self::assertArrayHasKey('normal_key', $options, 'the healthy file must still be scanned');

            $oversized = array_values(array_filter(
                $result['errors'],
                static fn (array $e): bool => str_contains($e['file'], 'huge.php'),
            ));
            self::assertCount(1, $oversized, 'the oversized file must be reported exactly once');
            self::assertSame('E_SIZE', $oversized[0]['code']);
        } finally {
            @unlink($root . '/plugin.php');
            @unlink($root . '/huge.php');
            @rmdir($root);
        }
    }

    public function test_a_long_generated_concatenation_scans_in_linear_time(): void
    {
        // Every visitor resolves every assignment, so a resolver that is
        // quadratic in a chain's length turned a 3000-term SQL builder into
        // minutes of scan time.
        $terms = [];
        for ($i = 0; $i < 3000; $i++) {
            $terms[] = $i % 2 === 0 ? "'s{$i}'" : '$v' . $i;
        }
        $chain = implode(' . ', $terms);

        $root = sys_get_temp_dir() . '/sediment-concat-test-' . getmypid();
        @mkdir($root);
        file_put_contents($root . '/plugin.php', "<?php\n\$q = {$chain};\nupdate_option({$chain}, \$q);\nadd_option('acme_' . 'version', '1');\n");

        try {
            $start = microtime(true);
            $result = (new Scanner())->scan($root);
            $elapsed = microtime(true) - $start;

            self::assertLessThan(5.0, $elapsed, 'a long concatenation must not be quadratic to resolve');
            self::assertSame([], $result['errors']);

            $options = $this->optionsByKey($result['findings']);
            self::assertArrayHasKey('acme_version', $options, 'short chains still resolve');

            $long = array_values(array_filter(
                $result['findings'],
                static fn (Finding $f): bool => $f->function === 'update_option',
            ));
            self::assertCount(1, $long);
            self::assertSame(Finding::CONFIDENCE_DYNAMIC, $long[0]->confidence, 'past the segment cap the key is dynamic');
        } finally {
            @unlink($root . '/plugin.php');
            @rmdir($root);
        }
    }

    public function test_malformed_php_is_recorded_as_an_error_not_thrown(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fuzz');

        self::assertGreaterThanOrEqual(1, count($result['errors']));
        foreach ($result['errors'] as $error) {
            self::assertSame('E_PARSE', $error['code'], 'a file that exists but will not parse is E_PARSE');
        }
    }

    public function test_a_syntax_error_records_e_parse_but_keeps_the_rest_of_the_file(): void
    {
        $root = sys_get_temp_dir() . '/sediment-partial-test-' . getmypid();
        @mkdir($root);
        file_put_contents($root . '/plugin.php', "<?php\nupdate_option('acme_before', 1);\nfunction f() {\n    \$x = ;\n}\nadd_option('acme_after', 2);\n");

        try {
            $result = (new Scanner())->scan($root);

            self::assertCount(1, $result['errors']);
            self::assertSame('E_PARSE', $result['errors'][0]['code']);

            $options = $this->optionsByKey($result['findings']);
            self::assertArrayHasKey('acme_before', $options);
            self::assertArrayHasKey('acme_after', $options, 'code past the syntax error is still read');
        } finally {
            @unlink($root . '/plugin.php');
            @rmdir($root);
        }
    }

    public function test_a_broken_uninstall_file_earns_no_cleanup_credit(): void
    {
        // PHP will not run a file that does not parse, so the removals that
        // could still be read from it never happen.
        $root = sys_get_temp_dir() . '/sediment-broken-uninstall-' . getmypid();
        @mkdir($root);
        file_put_contents($root . '/plugin.php', "<?php\nadd_option('acme_settings', []);\n");
        file_put_contents($root . '/uninstall.php', "<?php\ndelete_option('acme_settings');\n\$x = ;\n");

        try {
            $result = (new Scanner())->scan($root);

            self::assertSame('E_PARSE', $result['errors'][0]['code'] ?? null);
            self::assertFalse($result['cleanup']['has_uninstall_php']);
            self::assertFalse($this->optionsByKey($result['findings'])['acme_settings']->cleaned);
        } finally {
            @unlink($root . '/plugin.php');
            @unlink($root . '/uninstall.php');
            @rmdir($root);
        }
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
