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
 */

$root = dirname(__DIR__);
$run = $root . '/build/index';

/** @var array<string, string> $list */
$list = json_decode((string) file_get_contents(__DIR__ . '/index-plugins.json'), true);
$manifests = (array) glob($run . '/manifests/*.json');

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

$missing = [];
foreach ($list as $slug => $version) {
    if (!is_file($run . '/manifests/' . $slug . '.json')) {
        $missing[] = $slug;
    }
}

fwrite(STDOUT, sprintf(
    "assemble: %d manifests for %d pinned plugins; %d missing, %d fetch failure(s)\n",
    count($manifests),
    count($list),
    count($missing),
    count($failures),
));

if ($missing !== []) {
    fwrite(STDOUT, 'missing: ' . implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ', ...' : '') . "\n");
}

// A handful of fetch losses is the internet being the internet; a hole beyond
// half a percent means a shard died and the dataset would misrepresent itself
// as "the top 5,000" while silently being less.
$floor = (int) floor(count($list) * 0.995);
if (count($manifests) < $floor) {
    fwrite(STDERR, sprintf("coverage %d is below the floor of %d — refusing to build the dataset\n", count($manifests), $floor));
    exit(1);
}

exit(0);
