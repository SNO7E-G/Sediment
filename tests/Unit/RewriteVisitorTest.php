<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;

final class RewriteVisitorTest extends TestCase
{
    /** @return array{findings: list<Finding>, cleanup: array<string, mixed>, files: list<string>, errors: list<array{file: string, message: string}>} */
    private function scan(): array
    {
        return (new Scanner())->scan(dirname(__DIR__) . '/fixtures/rewrite-plugin');
    }

    public function test_it_detects_rules_endpoints_and_tags(): void
    {
        $rules = [];
        $dynamic = 0;
        foreach ($this->scan()['findings'] as $finding) {
            if ($finding->type !== 'rewrite_rule') {
                continue;
            }
            if ($finding->key !== null) {
                $rules[$finding->key] = $finding;
            } else {
                $dynamic++;
            }
        }

        self::assertArrayHasKey('^rwp/([^/]+)/?$', $rules);
        self::assertSame(Finding::CONFIDENCE_VERIFIED, $rules['^rwp/([^/]+)/?$']->confidence);

        // Endpoint name comes from a define() constant.
        self::assertArrayHasKey('rwp-portal', $rules);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $rules['rwp-portal']->confidence);

        self::assertSame(1, $dynamic, 'the variable rewrite tag must degrade to dynamic');
    }

    public function test_rules_appear_in_the_manifest_and_weigh_lightly(): void
    {
        $scan = $this->scan();
        $grade = (new Grader())->grade($scan['findings'], $scan['cleanup']);
        $manifest = Manifest::build($scan, $grade, 'x/rewrite-plugin', '2026-07-26T00:00:00Z');

        self::assertNotEmpty($manifest['creates']['rewrite_rules']);

        // No uninstall routine at all, so F — but rules alone are cheap, and the
        // score should stay well above zero rather than being punished like tables.
        self::assertSame('F', $grade->letter);
        self::assertGreaterThan(30, $grade->score);
    }
}
