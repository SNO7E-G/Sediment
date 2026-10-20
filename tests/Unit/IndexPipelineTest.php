<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The Index run's scripts and the CI that runs them: what gets published, and
 * what code is trusted to publish it.
 */
final class IndexPipelineTest extends TestCase
{
    private string $run = '';

    protected function setUp(): void
    {
        $this->run = sys_get_temp_dir() . '/sediment-index-run-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->run . '/manifests', 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->run)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->run, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->run);
    }

    /**
     * @param array<string, string> $list slug => version
     * @param array<string, string> $env
     */
    private function script(string $name, array $list, array $env = []): Process
    {
        file_put_contents($this->run . '/list.json', (string) json_encode($list));

        $process = new Process(
            [\PHP_BINARY, dirname(__DIR__, 2) . '/scripts/' . $name],
            null,
            ['INDEX_RUN' => $this->run, 'INDEX_LIST' => $this->run . '/list.json'] + $env,
        );
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    private const MANIFEST = '{"grade": "A", "coverage": {}, "creates": {}}';

    public function test_assemble_counts_coverage_against_the_pinned_list_not_the_files(): void
    {
        // Ten pinned, at most one may be missing. Eight are present, plus
        // three stale manifests for plugins no longer on the list: eleven
        // files clear a file-count floor of nine, but two pinned plugins are
        // missing and the dataset would not be what it says it is.
        $list = [];
        foreach (range(1, 10) as $i) {
            $list['plugin-' . $i] = '1.0';
        }
        foreach (range(1, 8) as $i) {
            file_put_contents($this->run . '/manifests/plugin-' . $i . '.json', self::MANIFEST);
        }
        foreach (['stale-a', 'stale-b', 'stale-c'] as $slug) {
            file_put_contents($this->run . '/manifests/' . $slug . '.json', self::MANIFEST);
        }

        $process = $this->script('index-assemble.php', $list);
        self::assertSame(1, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('2 pinned plugins have no manifest, more than the 1 allowed', $process->getErrorOutput());

        // One missing is within the floor.
        file_put_contents($this->run . '/manifests/plugin-9.json', self::MANIFEST);
        self::assertSame(0, $this->script('index-assemble.php', $list)->getExitCode());
    }

    public function test_a_resumed_shard_keeps_the_provenance_its_earlier_run_recorded(): void
    {
        // already-done was fetched and scanned by the interrupted run; its
        // provenance exists only in the shard record. still-to-do failed to
        // fetch then and is served from the cache now, so no network is used.
        file_put_contents($this->run . '/manifests/already-done.json', self::MANIFEST);
        file_put_contents($this->run . '/provenance-shard-0.json', (string) json_encode(['already-done' => ['version' => '1.0', 'sha256' => 'aaa']]));
        file_put_contents($this->run . '/fetch-failures-shard-0.json', (string) json_encode(['still-to-do' => ['version' => '2.0', 'error' => 'timed out']]));

        mkdir($this->run . '/cache/still-to-do-2.0', 0777, true);
        file_put_contents($this->run . '/cache/still-to-do-2.0/still-to-do.php', "<?php\nupdate_option('still_to_do', 1);\n");
        file_put_contents($this->run . '/cache/still-to-do-2.0.sha256', 'bbb');

        $process = $this->script('index-shard.php', ['already-done' => '1.0', 'still-to-do' => '2.0'], ['SHARD' => '0', 'SHARDS' => '1', 'JOBS' => '1']);
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        self::assertSame(
            ['already-done' => ['version' => '1.0', 'sha256' => 'aaa'], 'still-to-do' => ['version' => '2.0', 'sha256' => 'bbb']],
            json_decode((string) file_get_contents($this->run . '/provenance-shard-0.json'), true),
        );
        self::assertSame([], json_decode((string) file_get_contents($this->run . '/fetch-failures-shard-0.json'), true));
        self::assertFileExists($this->run . '/manifests/still-to-do.json');
    }

    public function test_every_action_is_pinned_to_a_commit_and_wordpress_to_a_checksum(): void
    {
        // A tag can be moved to other code; a commit cannot. The release job
        // holds a write token and a signing identity.
        $workflows = glob(dirname(__DIR__, 2) . '/.github/workflows/*.yml') ?: [];
        self::assertNotSame([], $workflows);

        foreach ($workflows as $workflow) {
            preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)/m', (string) file_get_contents($workflow), $uses);
            foreach ($uses[1] as $action) {
                if (str_starts_with($action, './')) {
                    continue; // a workflow in this repository, pinned by the commit itself
                }

                self::assertMatchesRegularExpression('/@[0-9a-f]{40}$/', $action, basename($workflow) . ' uses ' . $action);
            }
        }

        $integration = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/integration.yml');
        self::assertStringNotContainsString('latest.tar.gz', $integration);
        self::assertMatchesRegularExpression('/WP_SHA256: [0-9a-f]{64}/', $integration);
        self::assertStringContainsString('sha256sum -c', $integration);
    }
}
