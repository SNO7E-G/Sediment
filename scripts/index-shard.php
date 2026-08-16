<?php

declare(strict_types=1);

/*
 * One shard of the Index run: fetch and scan every SHARD-th plugin from the
 * pinned list in scripts/index-plugins.json.
 *
 * Round-robin sharding spreads the big plugins evenly across shards. Plugins
 * are staged in waves into directories named exactly by slug — the pilot's one
 * recorded pipeline rule, so `plugin.slug` in every manifest is the real
 * wordpress.org slug — scanned with `sediment batch`, and deleted, keeping the
 * disk footprint to one wave regardless of shard size.
 *
 * Environment: SHARD (0-based, default 0), SHARDS (default 1), JOBS (default 4).
 * Everything is resumable: manifests already written are skipped.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Sediment\Source\WordPressOrgClient;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$run = $root . '/build/index';
$shard = max(0, (int) (getenv('SHARD') ?: 0));
$shards = max(1, (int) (getenv('SHARDS') ?: 1));
$jobs = max(1, (int) (getenv('JOBS') ?: 4));
const WAVE_SIZE = 100;

@mkdir($run . '/manifests', 0777, true);

/** @var array<string, string> $list */
$list = json_decode((string) file_get_contents(__DIR__ . '/index-plugins.json'), true);

$mine = [];
$position = 0;
foreach ($list as $slug => $version) {
    if ($position++ % $shards === $shard) {
        $mine[$slug] = $version;
    }
}

$pending = array_filter(
    $mine,
    static fn (string $version, string $slug): bool => !is_file($run . '/manifests/' . $slug . '.json'),
    ARRAY_FILTER_USE_BOTH,
);

fwrite(STDOUT, sprintf("shard %d/%d: %d plugins, %d pending\n", $shard, $shards, count($mine), count($pending)));

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
}

$client = new WordPressOrgClient($run . '/cache');
$provenance = [];
$fetchFailures = [];
$wave = 0;

foreach (array_chunk(array_keys($pending), WAVE_SIZE) as $slugs) {
    $wave++;
    $stage = $run . '/stage-' . $shard;
    removeDir($stage);
    @mkdir($stage, 0777, true);

    $staged = 0;
    foreach ($slugs as $slug) {
        $version = $pending[$slug];

        try {
            $result = $client->fetch($slug, $version);
            if (!@rename($result['path'], $stage . '/' . $slug)) {
                throw new RuntimeException('could not move the fetched copy into the stage');
            }
            @unlink($result['path'] . '.sha256');
            $provenance[$slug] = ['version' => $version, 'sha256' => $result['sha256']];
            $staged++;
            usleep(250000); // stay polite to wordpress.org
        } catch (Throwable $e) {
            $fetchFailures[$slug] = ['version' => $version, 'error' => $e->getMessage()];
            fwrite(STDOUT, "FETCH FAILED {$slug} {$version}: {$e->getMessage()}\n");
        }
    }

    file_put_contents($run . '/provenance-shard-' . $shard . '.json', json_encode($provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($run . '/fetch-failures-shard-' . $shard . '.json', json_encode($fetchFailures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    if ($staged === 0) {
        removeDir($stage);
        continue;
    }

    $batch = new Process([
        PHP_BINARY, $root . '/bin/sediment', 'batch', $stage,
        '--out', $run . '/manifests',
        '--report', $run . '/report-shard-' . $shard . '-wave-' . $wave . '.json',
        '--resume', '--timeout', '600', '--memory-limit', '512M', '--jobs', (string) $jobs,
    ]);
    $batch->setTimeout(null);
    $batch->run(static function (string $type, string $buffer): void {
        fwrite(STDOUT, $buffer);
    });

    removeDir($stage);

    fwrite(STDOUT, sprintf(
        "shard %d wave %d done (batch exit %d), %d manifest(s) so far\n",
        $shard,
        $wave,
        (int) $batch->getExitCode(),
        count((array) glob($run . '/manifests/*.json')),
    ));
}

fwrite(STDOUT, sprintf(
    "SHARD %d DONE: %d fetch failure(s)\n",
    $shard,
    count($fetchFailures),
));
