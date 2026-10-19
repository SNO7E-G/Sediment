<?php

declare(strict_types=1);

/*
 * Pre-flight for the Index dataset, run after every shard's manifests have
 * been merged into build/index/manifests: merge the per-shard provenance and
 * fetch-failure records, and refuse to continue when coverage fell short.
 *
 * The QA gate proper (core artifacts, schema, zero-file scans) is
 * `sediment index`, which runs after this; this script only answers "did the
 * run actually cover the list it pinned".
 *
 * Environment: INDEX_RUN (default build/index) and INDEX_LIST (default
 * scripts/index-plugins.json), for running it against another directory.
 */

$root = dirname(__DIR__);
$run = getenv('INDEX_RUN') ?: $root . '/build/index';
$listFile = getenv('INDEX_LIST') ?: __DIR__ . '/index-plugins.json';

$list = json_decode((string) file_get_contents($listFile), true);
if (!is_array($list) || $list === []) {
    fwrite(STDERR, "assemble: the pinned list {$listFile} is missing or empty\n");
    exit(1);
}

$provenance = [];
foreach ((array) glob($run . '/meta/provenance-shard-*.json') as $file) {
    $provenance += (array) json_decode((string) file_get_contents((string) $file), true);
}

$failures = [];
foreach ((array) glob($run . '/meta/fetch-failures-shard-*.json') as $file) {
    $failures += (array) json_decode((string) file_get_contents((string) $file), true);
}

ksort($provenance);
ksort($failures);
file_put_contents($run . '/provenance.json', json_encode($provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($run . '/fetch-failures.json', json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Coverage is counted against the pinned list, never by counting files: a
// stale manifest for a plugin no longer on the list would otherwise stand in
// for one that is missing.
$missing = [];
foreach (array_keys($list) as $slug) {
    if (!is_file($run . '/manifests/' . $slug . '.json')) {
        $missing[] = (string) $slug;
    }
}

$extra = [];
foreach ((array) glob($run . '/manifests/*.json') as $file) {
    if (!isset($list[basename((string) $file, '.json')])) {
        $extra[] = basename((string) $file);
    }
}

fwrite(STDOUT, sprintf(
    "assemble: %d of %d pinned plugins have a manifest; %d missing, %d fetch failure(s), %d manifest(s) not on the list\n",
    count($list) - count($missing),
    count($list),
    count($missing),
    count($failures),
    count($extra),
));

if ($missing !== []) {
    fwrite(STDOUT, 'missing: ' . implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ', ...' : '') . "\n");
}

if ($extra !== []) {
    fwrite(STDOUT, 'not on the list: ' . implode(', ', array_slice($extra, 0, 20)) . (count($extra) > 20 ? ', ...' : '') . "\n");
}

// A handful of fetch losses is the internet being the internet; a hole beyond
// half a percent means a shard died and the dataset would misrepresent itself
// as "the top 5,000" while silently being less.
$allowedMissing = count($list) - (int) floor(count($list) * 0.995);
if (count($missing) > $allowedMissing) {
    fwrite(STDERR, sprintf(
        "%d pinned plugins have no manifest, more than the %d allowed — refusing to build the dataset\n",
        count($missing),
        $allowedMissing,
    ));
    exit(1);
}

exit(0);
