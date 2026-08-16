<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Scanner;
use Sediment\Command\IndexCommand;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IndexCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sediment-index-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($this->dir . '/manifests', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/manifests', '/out'] as $sub) {
            foreach ((array) glob($this->dir . $sub . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->dir . $sub);
        }
        @rmdir($this->dir);
    }

    /** Write a real manifest for an inline plugin body, under a given slug. */
    private function writeManifest(string $slug, string $body): void
    {
        $plugin = $this->dir . '/' . $slug;
        @mkdir($plugin, 0777, true);
        file_put_contents($plugin . '/' . $slug . '.php', $body);

        $scan = (new Scanner())->scan($plugin);
        $manifest = Manifest::build($scan, (new Grader())->grade($scan['findings'], $scan['cleanup']), $plugin, '2026-08-16T00:00:00Z');
        file_put_contents($this->dir . '/manifests/' . $slug . '.json', Manifest::toJson($manifest));

        @unlink($plugin . '/' . $slug . '.php');
        @rmdir($plugin);
    }

    public function test_it_builds_a_reverse_lookup_that_merges_plugins_writing_the_same_key(): void
    {
        $this->writeManifest('alpha', "<?php\nupdate_option('shared_flag', 1);\nupdate_option('alpha_own', 1);\n");
        $this->writeManifest('beta', "<?php\nupdate_option('shared_flag', 2);\n");

        $tester = new CommandTester(new IndexCommand());
        $tester->execute(['manifests' => $this->dir . '/manifests', '--out' => $this->dir . '/out']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $lookup = json_decode((string) file_get_contents($this->dir . '/out/reverse-lookup.json'), true);
        self::assertSame(['alpha', 'beta'], $lookup['option:shared_flag']);
        self::assertSame(['alpha'], $lookup['option:alpha_own']);

        $stats = json_decode((string) file_get_contents($this->dir . '/out/stats.json'), true);
        self::assertSame(2, $stats['plugins']);
        self::assertSame(3, $stats['artifacts']['total']);
        self::assertSame(3, $stats['artifacts']['left_behind']);

        $qa = json_decode((string) file_get_contents($this->dir . '/out/qa.json'), true);
        self::assertSame([], $qa['core_artifacts_in_creates']);
    }

    public function test_a_manifest_attributing_a_core_artifact_fails_qa_and_the_build(): void
    {
        // Hand-forged: the analyzer itself never puts a core key in `creates`,
        // so QA's job is to catch a manifest that did not come from it intact.
        $this->writeManifest('honest', "<?php\nupdate_option('honest_opt', 1);\n");

        $forged = json_decode((string) file_get_contents($this->dir . '/manifests/honest.json'), true);
        $forged['plugin']['slug'] = 'forged';
        $forged['creates']['options'][] = [
            'key' => 'active_plugins',
            'autoload' => 'yes',
            'confidence' => 'verified',
            'cleaned' => false,
            'sources' => [['file' => 'x.php', 'line' => 1]],
        ];
        file_put_contents($this->dir . '/manifests/forged.json', Manifest::toJson($forged));

        $tester = new CommandTester(new IndexCommand());
        $tester->execute(['manifests' => $this->dir . '/manifests', '--out' => $this->dir . '/out']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        $qa = json_decode((string) file_get_contents($this->dir . '/out/qa.json'), true);
        self::assertSame(['forged: option:active_plugins'], $qa['core_artifacts_in_creates']);
        self::assertStringContainsString('must not be published', $tester->getDisplay());
    }

    public function test_every_other_qa_category_also_fails_the_build(): void
    {
        // Each category is a build-stopper; asserting them all here means a
        // future edit cannot quietly drop one from the violation sum.
        $this->writeManifest('honest', "<?php\nupdate_option('honest_opt', 1);\n");
        $honest = json_decode((string) file_get_contents($this->dir . '/manifests/honest.json'), true);

        $forgeries = [
            'wrong-schema' => static function (array $m): array {
                $m['schema_version'] = '1.0';

                return $m;
            },
            'never-scanned' => static function (array $m): array {
                $m['coverage']['files_scanned'] = 0;

                return $m;
            },
        ];

        foreach ($forgeries as $name => $forge) {
            file_put_contents($this->dir . '/manifests/' . $name . '.json', Manifest::toJson($forge($honest)));

            $tester = new CommandTester(new IndexCommand());
            $tester->execute(['manifests' => $this->dir . '/manifests', '--out' => $this->dir . '/out']);

            self::assertSame(Command::FAILURE, $tester->getStatusCode(), "{$name} must fail QA");
            @unlink($this->dir . '/manifests/' . $name . '.json');
        }

        // Truncated JSON that decodes but is missing required fields.
        file_put_contents($this->dir . '/manifests/truncated.json', '{"schema_version": "2.0"}');

        $tester = new CommandTester(new IndexCommand());
        $tester->execute(['manifests' => $this->dir . '/manifests', '--out' => $this->dir . '/out']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'a truncated manifest must fail QA');
        $qa = json_decode((string) file_get_contents($this->dir . '/out/qa.json'), true);
        self::assertSame(['truncated.json'], $qa['unreadable']);
    }

    public function test_an_empty_directory_fails_rather_than_building_an_empty_index(): void
    {
        $tester = new CommandTester(new IndexCommand());
        $tester->execute(['manifests' => $this->dir . '/manifests', '--out' => $this->dir . '/out']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
