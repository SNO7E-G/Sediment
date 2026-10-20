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
 * Environment: SHARD (0-based, default 0), SHARDS (default 1), JOBS (default 4),
 * INDEX_RUN (default build/index), INDEX_LIST (default scripts/index-plugins.json).
 * Everything is resumable: manifests already written whole are skipped, and
 * the provenance and fetch-failure records carry over from the earlier run.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Sediment\Manifest\Manifest;
use Sediment\Source\WordPressOrgClient;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$run = getenv('INDEX_RUN') ?: $root . '/build/index';
$shard = max(0, (int) (getenv('SHARD') ?: 0));
$shards = max(1, (int) (getenv('SHARDS') ?: 1));
$jobs = max(1, (int) (getenv('JOBS') ?: 4));
const WAVE_SIZE = 100;

@mkdir($run . '/manifests', 0777, true);

/** @var array<string, string> $list */
$list = json_decode((string) file_get_contents(getenv('INDEX_LIST') ?: __DIR__ . '/index-plugins.json'), true);

$mine = [];
$position = 0;
foreach ($list as $slug => $version) {
    if ($position++ % $shards === $shard) {
        $mine[$slug] = $version;
    }
}

$pending = array_filter(
    $mine,
    // A manifest cut short by a killed run is no manifest: stage it again.
    static fn (string $version, string $slug): bool => Manifest::readFile($run . '/manifests/' . $slug . '.json') === null,
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

/**
 * A shard record written by an earlier, interrupted run of this shard.
 *
 * @return array<string, mixed>
 */
function shardRecord(string $file): array
{
    $record = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

    return is_array($record) ? $record : [];
}

// Resuming must not forget what the interrupted run already recorded: the
// plugins it staged have their manifests now and will not be fetched again,
// so their provenance exists only in these files.
$client = new WordPressOrgClient($run . '/cache');
$provenance = shardRecord($run . '/provenance-shard-' . $shard . '.json');
$fetchFailures = shardRecord($run . '/fetch-failures-shard-' . $shard . '.json');
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
            unset($fetchFailures[$slug]); // an earlier run's failure, since recovered
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

    // 1 means some plugins in the wave could not be scanned — expected at this
    // scale, and the assemble step's coverage floor judges it. Anything else
    // means batch itself broke, and carrying on would publish a hollow shard.
    if (!in_array($batch->getExitCode(), [0, 1], true)) {
        fwrite(STDERR, sprintf("shard %d wave %d: batch failed with exit %d\n", $shard, $wave, (int) $batch->getExitCode()));
        exit(1);
    }

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
