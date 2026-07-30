<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Application;

/**
 * The version is stamped into every manifest as `analyzer_version`. When it
 * drifts, published data is mislabelled — and it did drift: manifests claimed
 * 0.1.0-dev for four releases because nothing checked.
 */
final class VersionTest extends TestCase
{
    public function test_the_application_version_matches_the_changelog(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');

        self::assertSame(
            1,
            preg_match('/^## \[([0-9]+\.[0-9]+\.[0-9]+[^\]]*)\]/m', $changelog, $matches),
            'the changelog should open with a release heading',
        );

        self::assertSame(
            $matches[1],
            Application::VERSION,
            'Application::VERSION must match the newest CHANGELOG entry, or manifests record a version that was never released',
        );
    }
}
