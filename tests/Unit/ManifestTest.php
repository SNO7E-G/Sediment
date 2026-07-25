<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;

final class ManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(string $fixture): array
    {
        $path = dirname(__DIR__) . '/fixtures/' . $fixture;
        $scan = (new Scanner())->scan($path);

        return Manifest::build($scan, (new Grader())->grade($scan['findings'], $scan['cleanup']), $path, '2026-07-22T10:00:00Z');
    }

    public function test_it_reports_plugin_metadata_grade_and_coverage(): void
    {
        $manifest = $this->manifest('partial-plugin');

        self::assertSame('1.0', $manifest['schema_version']);
        self::assertSame('partial-plugin', $manifest['plugin']['slug']);
        self::assertSame('Partial Plugin (fixture)', $manifest['plugin']['name']);
        self::assertSame('D', $manifest['grade']);
        self::assertSame(4, $manifest['coverage']['write_calls_found']);
        self::assertSame(1.0, $manifest['coverage']['resolution_rate']);
        self::assertTrue($manifest['cleanup']['has_uninstall_php']);
    }

    public function test_creates_carry_per_item_confidence_cleaned_and_sources(): void
    {
        $creates = $this->manifest('partial-plugin')['creates'];

        $options = array_column($creates['options'], null, 'key');
        self::assertTrue($options['pp_settings']['cleaned']);
        self::assertFalse($options['pp_temp']['cleaned']);
        self::assertSame('yes', $options['pp_settings']['autoload']);
        self::assertSame('partial-plugin.php', $options['pp_settings']['sources'][0]['file']);

        self::assertSame('{prefix}pp_data', $creates['tables'][0]['key']);
        self::assertSame('daily', $creates['cron'][0]['recurrence']);
    }

    public function test_v0_2_artifact_types_are_present_as_empty_arrays(): void
    {
        $creates = $this->manifest('clean-plugin')['creates'];

        foreach (['post_meta', 'user_meta', 'roles', 'post_types', 'directories'] as $key) {
            self::assertSame([], $creates[$key], "{$key} must exist so the schema never breaks");
        }
    }

    public function test_unresolvable_writes_are_listed_under_unresolved(): void
    {
        $manifest = $this->manifest('dirty-plugin');

        self::assertCount(1, $manifest['unresolved']);
        self::assertSame('update_option', $manifest['unresolved'][0]['function']);
        self::assertNotSame('', $manifest['unresolved'][0]['expression']);
    }

    public function test_the_manifest_is_json_serializable(): void
    {
        $json = json_encode($this->manifest('clean-plugin'));

        self::assertIsString($json);
        self::assertSame('A', json_decode($json, true)['grade']);
    }
}
