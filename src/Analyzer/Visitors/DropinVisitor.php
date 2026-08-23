<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;
use Sediment\Analyzer\WpRoots;

/**
 * Detects drop-in and must-use plugin files a plugin installs (C5/C6).
 *
 * These are the leftovers that outlive the plugin with its boots on:
 *
 *  - A drop-in (`advanced-cache.php`, `object-cache.php`, `db.php`,
 *    `sunrise.php`, …) sits in wp-content and is loaded by WordPress itself
 *    on every request. A leftover `db.php` or `object-cache.php` can slow or
 *    break an entire site long after its author was deleted.
 *  - A file dropped into `mu-plugins/` runs before normal plugins load and is
 *    not manageable from the Plugins screen — it survives until someone
 *    removes it by hand.
 *
 * Writes are read from `file_put_contents()`, `copy()` (the destination), and
 * `$wp_filesystem->put_contents()`. Only exact, resolved targets are recorded:
 * the file's name IS the artifact, so a pattern like `{content_dir}/cache_*.php`
 * cannot be honestly attributed to either type and is left to coverage.
 * Arbitrary other files under wp-content are not footprint in this sense and
 * are not reported.
 */
final class DropinVisitor extends AbstractDetectionVisitor
{
    /** Drop-ins WordPress loads from the wp-content root, by exact name. */
    private const DROPINS = [
        'advanced-cache.php' => true,
        'db.php' => true,
        'db-error.php' => true,
        'install.php' => true,
        'maintenance.php' => true,
        'object-cache.php' => true,
        'sunrise.php' => true,
    ];

    protected function inspect(Node $node): void
    {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $fn = strtolower($node->name->toString());
            // copy($source, $dest): the destination is what persists.
            if ($fn === 'copy') {
                $this->record($node, 'copy', $this->argValue($node->getArgs(), 1, 'to'));

                return;
            }

            if ($fn === 'file_put_contents') {
                $this->record($node, 'file_put_contents', $this->argValue($node->getArgs(), 0, 'filename'));
            }

            return;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            if (strtolower((string) $node->name) === 'put_contents') {
                $this->record($node, '$wp_filesystem->put_contents', $this->argValue($node->getArgs(), 0, 'filepath'));
            }
        }
    }

    private function record(FuncCall|MethodCall $node, string $function, ?Node\Expr $target): void
    {
        if ($target === null) {
            return;
        }

        $resolution = $this->resolvePath($target);
        if ($resolution === null || !$resolution->isResolved()) {
            return; // cannot name the file exactly — never guess at this type
        }

        $type = self::classifyPath((string) $resolution->value);
        if ($type === null) {
            return;
        }

        $this->findings[] = new Finding(
            type: $type,
            function: $function,
            key: (string) $resolution->value,
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $resolution->raw,
        );
    }

    /**
     * The portable path decides the artifact type, or rejects it. Static and
     * shared with the cleanup reader, so a create and its removal always agree
     * on what a path means.
     */
    public static function classifyPath(string $path): ?string
    {
        $basename = basename(strtolower($path));

        if (str_starts_with($path, '{content_dir}/') && isset(self::DROPINS[$basename])) {
            return 'dropin';
        }

        if (str_starts_with($path, '{mu_plugins}/') && str_ends_with($basename, '.php')) {
            return 'muplugin';
        }

        return null;
    }

    /**
     * Resolve through the shared root tokens first, then fall back to plain
     * resolution for paths that need no rewrite.
     */
    private function resolvePath(Node\Expr $expr): ?Resolution
    {
        $rooted = WpRoots::split($expr);
        if ($rooted === null) {
            return $this->resolveKey($expr);
        }

        [$token, $remainder] = $rooted;
        if ($remainder === null) {
            return null;
        }

        $tail = $this->resolveKey($remainder);

        if ($tail->confidence === Finding::CONFIDENCE_VERIFIED || $tail->confidence === Finding::CONFIDENCE_RESOLVED) {
            if ($tail->value === '') {
                return null;
            }

            $value = $token . $tail->value;

            return $tail->confidence === Finding::CONFIDENCE_VERIFIED
                ? Resolution::verified($value)
                : Resolution::resolved($value);
        }

        return null; // a partly-known filename cannot name a drop-in
    }
}
