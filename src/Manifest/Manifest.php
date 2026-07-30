<?php

declare(strict_types=1);

namespace Sediment\Manifest;

use Sediment\Analyzer\Finding;
use Sediment\Analyzer\WordPressCore;
use Sediment\Application;

/**
 * Builds the JSON manifest (M10, §9) — the machine-readable form of a scan, and
 * the input to everything downstream: the Index, CI checks, and the WordPress
 * plugin.
 *
 * Findings are grouped by key so the same option written from three places is
 * one entry with three `sources`. Artifact types that arrive in v0.2 are emitted
 * as empty arrays so consumers never have to handle a missing field.
 */
final class Manifest
{
    public const SCHEMA_VERSION = '1.0';

    /** Finding type => manifest group (§9). */
    private const TYPE_KEYS = [
        'option' => 'options',
        'table' => 'tables',
        'cron' => 'cron',
        'transient' => 'transients',
        'post_meta' => 'post_meta',
        'user_meta' => 'user_meta',
        'term_meta' => 'term_meta',
        'comment_meta' => 'comment_meta',
        'role' => 'roles',
        'capability' => 'capabilities',
        'post_type' => 'post_types',
        'taxonomy' => 'taxonomies',
        'directory' => 'directories',
        'rewrite_rule' => 'rewrite_rules',
        'action' => 'actions',
    ];

    /** Not detected yet; present so the schema never breaks for consumers. */
    private const FUTURE_KEYS = [];

    /**
     * @param array{files: list<string>, findings: list<Finding>, errors: list<array{file: string, message: string}>, cleanup: array{has_uninstall_php: bool, has_uninstall_hook: bool, conditional?: bool, condition_option?: string|null, condition_default?: bool|string|null}} $scan
     * @return array<string, mixed>
     */
    public static function build(array $scan, Grade $grade, string $path, string $scannedAt): array
    {
        $findings = $scan['findings'];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'plugin' => self::plugin($path, $scan['files'], $scannedAt),
            'grade' => $grade->letter,
            'score' => $grade->score,
            'coverage' => self::coverage($findings),
            'cleanup' => [
                'has_uninstall_php' => $scan['cleanup']['has_uninstall_php'],
                'has_uninstall_hook' => $scan['cleanup']['has_uninstall_hook'],
                'conditional' => $scan['cleanup']['conditional'] ?? false,
                'condition_option' => $scan['cleanup']['condition_option'] ?? null,
                'condition_default' => $scan['cleanup']['condition_default'] ?? null,
            ],
            'creates' => self::creates($findings),
            'modifies_core' => self::modifiesCore($findings),
            'unresolved' => self::unresolved($findings),
        ];
    }

    /**
     * Serialise a manifest.
     *
     * `JSON_PRESERVE_ZERO_FRACTION` is not cosmetic: without it a
     * `resolution_rate` of exactly 1.0 encodes as `1` and decodes as an integer,
     * so the same scan round-trips to a different type. That makes `diff` report
     * a change where nothing changed, and gives the Index two types for one
     * field. Encoding goes through here so the flag cannot be forgotten.
     *
     * @param array<string, mixed> $manifest
     */
    public static function toJson(array $manifest): string
    {
        return (string) json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param list<string> $files
     * @return array<string, string|null>
     */
    private static function plugin(string $path, array $files, string $scannedAt): array
    {
        $root = rtrim(str_replace('\\', '/', $path), '/');
        $header = self::readHeader($root, $files);

        return [
            'slug' => basename($root) !== '' ? basename($root) : null,
            'name' => $header['name'],
            'version' => $header['version'],
            'source' => 'local',
            'scanned_at' => $scannedAt,
            'analyzer_version' => Application::VERSION,
        ];
    }

    /**
     * Read the WordPress plugin header. Like WordPress itself, only top-level
     * PHP files are considered.
     *
     * @param list<string> $files
     * @return array{name: string|null, version: string|null}
     */
    private static function readHeader(string $root, array $files): array
    {
        foreach ($files as $file) {
            $normalized = str_replace('\\', '/', $file);
            if (str_contains(substr($normalized, strlen($root) + 1), '/')) {
                continue; // not top level
            }

            $head = @file_get_contents($file, false, null, 0, 8192);
            if ($head === false || !preg_match('/^[ \t\/*#@]*Plugin Name:(.*)$/mi', $head, $name)) {
                continue;
            }

            $version = preg_match('/^[ \t\/*#@]*Version:(.*)$/mi', $head, $m) === 1 ? trim($m[1]) : null;

            return ['name' => trim($name[1]) ?: null, 'version' => $version ?: null];
        }

        return ['name' => null, 'version' => null];
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, int|float>
     */
    private static function coverage(array $findings): array
    {
        $counts = [
            Finding::CONFIDENCE_VERIFIED => 0,
            Finding::CONFIDENCE_RESOLVED => 0,
            Finding::CONFIDENCE_PATTERN => 0,
            Finding::CONFIDENCE_DYNAMIC => 0,
        ];

        foreach ($findings as $finding) {
            $counts[$finding->confidence] = ($counts[$finding->confidence] ?? 0) + 1;
        }

        $total = count($findings);
        $resolved = $counts[Finding::CONFIDENCE_VERIFIED] + $counts[Finding::CONFIDENCE_RESOLVED];

        return [
            'write_calls_found' => $total,
            'verified' => $counts[Finding::CONFIDENCE_VERIFIED],
            'resolved' => $counts[Finding::CONFIDENCE_RESOLVED],
            'pattern' => $counts[Finding::CONFIDENCE_PATTERN],
            'dynamic' => $counts[Finding::CONFIDENCE_DYNAMIC],
            'resolution_rate' => $total > 0 ? round($resolved / $total, 3) : 1.0,
        ];
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, list<array<string, mixed>>>
     */
    private static function creates(array $findings): array
    {
        $creates = array_fill_keys(array_values(self::TYPE_KEYS), []);
        $creates += array_fill_keys(self::FUTURE_KEYS, []);

        /** @var array<string, array<string, array<string, mixed>>> $grouped */
        $grouped = [];

        foreach ($findings as $finding) {
            if ($finding->key === null) {
                continue; // unresolvable writes are reported under `unresolved`
            }

            // Writing to `active_plugins` or `blogname` is touching WordPress's
            // own data, not leaving something behind. Reporting it under
            // `creates` would attribute core rows to a plugin, which is exactly
            // the misattribution this project exists to avoid — and would put
            // core keys into what consumers treat as a removable set. They are
            // reported under `modifies_core` instead.
            if (WordPressCore::isCore($finding)) {
                continue;
            }

            if (!isset(self::TYPE_KEYS[$finding->type])) {
                // A detector emitting a type with no manifest group would drop it
                // silently, which is the one way this document could lie. Fail
                // loudly instead so it is caught the first time it happens.
                throw new \LogicException(sprintf('No manifest group for finding type "%s".', $finding->type));
            }

            $group = self::TYPE_KEYS[$finding->type];
            $source = ['file' => $finding->file, 'line' => $finding->line];

            if (!isset($grouped[$group][$finding->key])) {
                $grouped[$group][$finding->key] = self::item($finding, $source);
                continue;
            }

            // Same key written from several places: merge into one entry. It is
            // cleaned only when every write of it is cleaned — a cron hook
            // scheduled both with and without arguments needs both cleared.
            $entry = &$grouped[$group][$finding->key];
            $entry['sources'][] = $source;
            $entry['cleaned'] = $entry['cleaned'] && $finding->cleaned === true;
            if (($entry['autoload'] ?? null) !== null && $finding->autoload === 'yes') {
                $entry['autoload'] = 'yes';
            }
            unset($entry);
        }

        // Sorted by key rather than left in scan order. The manifest is a
        // published document that gets diffed, so its ordering should depend on
        // its contents alone — not on the order a filesystem happened to hand
        // files over.
        foreach ($grouped as $group => $entries) {
            ksort($entries);
            $creates[$group] = array_values($entries);
        }

        return $creates;
    }

    /**
     * @param array{file: string, line: int} $source
     * @return array<string, mixed>
     */
    private static function item(Finding $finding, array $source): array
    {
        $item = ['key' => $finding->key];

        if ($finding->type === 'option') {
            $item['autoload'] = $finding->autoload ?? 'unknown';
        }
        if ($finding->type === 'cron' || $finding->type === 'action') {
            $item['recurrence'] = $finding->recurrence;
        }

        $item['confidence'] = $finding->confidence;
        $item['cleaned'] = $finding->cleaned === true;
        $item['sources'] = [$source];

        return $item;
    }

    /**
     * WordPress core artifacts the plugin writes to. Worth knowing — a plugin
     * that rewrites `active_plugins` or `blogname` is doing something notable —
     * but it is not the plugin's own footprint and must never be mistaken for
     * something removable.
     *
     * @param list<Finding> $findings
     * @return list<array<string, mixed>>
     */
    private static function modifiesCore(array $findings): array
    {
        $byKey = [];

        foreach ($findings as $finding) {
            if ($finding->key === null || !WordPressCore::isCore($finding)) {
                continue;
            }

            $id = $finding->type . ':' . $finding->key;
            $source = ['file' => $finding->file, 'line' => $finding->line];

            if (!isset($byKey[$id])) {
                $byKey[$id] = [
                    'type' => $finding->type,
                    'key' => $finding->key,
                    'confidence' => $finding->confidence,
                    'sources' => [$source],
                ];
                continue;
            }

            $byKey[$id]['sources'][] = $source;
        }

        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * @param list<Finding> $findings
     * @return list<array<string, mixed>>
     */
    private static function unresolved(array $findings): array
    {
        $unresolved = [];
        foreach ($findings as $finding) {
            if ($finding->key !== null) {
                continue;
            }

            $unresolved[] = [
                'function' => $finding->function,
                'expression' => $finding->expression ?? '',
                'file' => $finding->file,
                'line' => $finding->line,
            ];
        }

        return $unresolved;
    }
}
