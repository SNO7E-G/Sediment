<?php

declare(strict_types=1);

namespace Sediment\Tests\Golden;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;
use Sediment\Source\WordPressOrgClient;

/**
 * Checks Sediment's output against ten real, pinned plugins.
 *
 * Hand-written fixtures prove the analyzer handles the shapes we thought of.
 * This proves it handles the shapes other people actually wrote — and, more
 * importantly, it makes any change in what we tell users about a real plugin
 * visible in a diff instead of silent.
 *
 * Plugins are fetched from wordpress.org and cached, never committed. Set
 * SEDIMENT_UPDATE_GOLDEN=1 to rewrite the expectations after an intentional
 * change; the diff it produces is the point, so read it before committing.
 */
#[Group('golden')]
final class GoldenManifestTest extends TestCase
{
    private static function cacheDirectory(): string
    {
        return getenv('SEDIMENT_PLUGIN_CACHE') ?: dirname(__DIR__, 2) . '/build/plugins';
    }

    private static function expectedFile(string $slug): string
    {
        return __DIR__ . '/manifests/' . $slug . '.json';
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function corpus(): iterable
    {
        foreach (GoldenCorpus::PLUGINS as $slug => [$version, $_why]) {
            yield $slug => [$slug, $version];
        }
    }

    #[DataProvider('corpus')]
    public function test_the_manifest_matches_the_recorded_expectation(string $slug, string $version): void
    {
        $path = self::cacheDirectory() . '/' . $slug . '-' . $version;

        if (!is_dir($path)) {
            if (getenv('SEDIMENT_FETCH_GOLDEN') === false) {
                self::markTestSkipped(sprintf('%s %s is not cached. Run: php bin/sediment fetch %s %s --cache=%s', $slug, $version, $slug, $version, self::cacheDirectory()));
            }

            (new WordPressOrgClient(self::cacheDirectory()))->fetch($slug, $version);
        }

        $scan = (new Scanner())->scan($path);
        $grade = (new Grader())->grade($scan['findings'], $scan['cleanup']);
        $actual = GoldenCorpus::normalise(Manifest::build($scan, $grade, $path, '1970-01-01T00:00:00Z'));

        // The plugin's own slug on disk carries the version; the manifest should
        // record the plugin, not where it happened to be unpacked.
        $actual['plugin']['slug'] = $slug;

        $expectedFile = self::expectedFile($slug);

        if (getenv('SEDIMENT_UPDATE_GOLDEN') !== false) {
            @mkdir(dirname($expectedFile), 0777, true);
            file_put_contents($expectedFile, Manifest::toJson($actual) . "\n");
            self::markTestSkipped("Recorded a new expectation for {$slug}.");
        }

        self::assertFileExists($expectedFile, "No recorded expectation for {$slug}; run with SEDIMENT_UPDATE_GOLDEN=1.");

        $expected = json_decode((string) file_get_contents($expectedFile), true);
        self::assertIsArray($expected);

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                "%s %s no longer produces the recorded manifest.\nIf the change is intended, rerun with SEDIMENT_UPDATE_GOLDEN=1 and describe it in the changelog.",
                $slug,
                $version,
            ),
        );
    }

    public function test_no_real_plugin_is_credited_with_creating_wordpress_core_data(): void
    {
        // Criterion 6, asserted across real plugins rather than fixtures. Plugins
        // genuinely do write `active_plugins` and `blogname`; that is touching
        // WordPress's own data, not leaving something behind, and it must never
        // appear in the set a consumer would treat as removable.
        $offences = [];

        foreach (array_keys(GoldenCorpus::PLUGINS) as $slug) {
            $file = self::expectedFile($slug);
            if (!is_file($file)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($file), true);

            $groupToType = array_flip(Manifest::TYPE_KEYS);

            foreach ($manifest['creates'] as $group => $items) {
                foreach ($items as $item) {
                    // The real mapping, not a naive de-pluralisation — which
                    // turned "capabilities" into a type nothing matches and
                    // made this check vacuous for two groups.
                    $finding = new \Sediment\Analyzer\Finding(
                        type: $groupToType[$group],
                        function: 'x',
                        key: $item['key'],
                        confidence: $item['confidence'],
                        file: 'x',
                        line: 1,
                    );

                    if (\Sediment\Analyzer\WordPressCore::isCore($finding)) {
                        $offences[] = "{$slug}: {$group}/{$item['key']}";
                    }
                }
            }
        }

        self::assertSame([], $offences, 'core artifacts were credited to plugins as their own creations');
    }

    public function test_the_corpus_covers_the_range_it_claims_to(): void
    {
        // A corpus of ten similar plugins would pass while proving little. These
        // are the properties it exists to spread across.
        $grades = [];
        $missing = [];

        foreach (array_keys(GoldenCorpus::PLUGINS) as $slug) {
            $file = self::expectedFile($slug);
            if (!is_file($file)) {
                $missing[] = $slug;
                continue;
            }

            $manifest = json_decode((string) file_get_contents($file), true);
            $grades[] = $manifest['grade'] ?? '?';
        }

        if ($missing !== []) {
            self::markTestSkipped('Expectations not recorded yet for: ' . implode(', ', $missing));
        }

        self::assertGreaterThanOrEqual(
            3,
            count(array_unique($grades)),
            'the corpus should span at least three different grades, or it is not testing the rubric: ' . implode(', ', $grades),
        );
    }
}
