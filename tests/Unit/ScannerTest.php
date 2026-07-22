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

    public function test_it_detects_literal_option_writes_as_verified(): void
    {
        $result = (new Scanner())->scan($this->fixture('dirty-plugin'));

        $verified = [];
        foreach ($result['options'] as $finding) {
            if ($finding->confidence === Finding::CONFIDENCE_VERIFIED) {
                $verified[$finding->key] = $finding->function;
            }
        }

        self::assertArrayHasKey('dirty_version', $verified);
        self::assertArrayHasKey('dirty_settings', $verified);
        self::assertArrayHasKey('dirty_network_flag', $verified);
        self::assertSame('add_site_option', $verified['dirty_network_flag']);
    }

    public function test_it_reports_unresolvable_keys_as_dynamic(): void
    {
        $result = (new Scanner())->scan($this->fixture('dirty-plugin'));

        $dynamic = array_filter(
            $result['options'],
            static fn (Finding $f): bool => $f->confidence === Finding::CONFIDENCE_DYNAMIC,
        );

        self::assertCount(1, $dynamic, 'the some_runtime_key() write must degrade to dynamic, not be dropped or guessed');
    }

    public function test_malformed_php_is_recorded_as_an_error_not_thrown(): void
    {
        $result = (new Scanner())->scan(__DIR__ . '/../fuzz');

        // The contract: scanning hostile input never throws; the bad file is
        // recorded and skipped (M14).
        self::assertGreaterThanOrEqual(1, count($result['errors']));
    }
}
