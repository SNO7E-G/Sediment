<?php

declare(strict_types=1);

namespace Sediment\Tests\Golden;

/**
 * The pinned set of real plugins Sediment's output is checked against.
 *
 * Chosen to span the grade range and the shapes that have historically broken
 * the analyzer, not the most popular plugins for their own sake. Every entry is
 * pinned to an exact version: a corpus tracking "latest" would rewrite its own
 * expectations whenever an author shipped, which is the opposite of a
 * regression net.
 *
 * Plugin source is never committed — it is GPL code belonging to other people
 * and would bloat the repository. Only the expected manifests are, and the
 * source is fetched from wordpress.org and cached.
 */
final class GoldenCorpus
{
    /**
     * slug => [version, why it earns a place in the corpus]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PLUGINS = [
        'classic-editor' => ['1.7.0', 'Small and tidy: short, mostly-literal code that should resolve almost completely.'],
        'health-check' => ['1.7.1', 'Small, written by the WordPress team itself, and a check that ordinary code stays boring.'],
        'akismet' => ['5.7', 'Long-lived and conservative: proves nothing regresses on simple, stable code.'],
        'contact-form-7' => ['6.1.6', 'Mid-sized, registers a custom post type, and cleans up only partially.'],
        'redirection' => ['5.9.0', 'Custom tables as its primary artifact, created through its own schema layer.'],
        'wp-super-cache' => ['3.1.1', 'Writes directories and files as its main footprint, which little else here does.'],
        'updraftplus' => ['1.26.6', 'Scheduling plus filesystem work, a combination the rest of the corpus misses.'],
        'wordfence' => ['8.2.2', 'Large and table-heavy, writing through its own storage layer.'],
        'wordpress-seo' => ['28.1', 'Very large (1,600+ files) and heavily indirect: the resolution-rate worst case.'],
        'woocommerce' => ['10.9.4', 'The richest single test: Action Scheduler, custom tables, roles and post types at once, across 3,500 scanned files.'],
    ];

    /**
     * Fields that legitimately differ between runs and must not be compared:
     * when the scan happened, which version of Sediment ran it, and where the
     * plugin happened to sit on disk.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function normalise(array $manifest): array
    {
        unset($manifest['plugin']['scanned_at'], $manifest['plugin']['analyzer_version']);

        return $manifest;
    }
}
