<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;

/**
 * Detects directories a plugin creates under a WordPress root: wp_mkdir_p()
 * and mkdir().
 *
 * Path normalization: the resolver already turns `$wpdb->prefix` into the
 * portable `{prefix}` token (§8) so a finding survives across installs with a
 * different table prefix. Filesystem roots need the analogous treatment, but
 * ExpressionResolver only resolves `define()`d constants — and plugins never
 * `define()` WP_CONTENT_DIR/WP_PLUGIN_DIR/ABSPATH themselves, so a path built
 * from them would otherwise degrade to `pattern`/`dynamic` and lose the root
 * entirely. Instead of teaching the shared resolver about specific WordPress
 * constants, this visitor special-cases the AST shape here: if the expression
 * is `ROOT . remainder` (a concatenation whose first operand is one of the
 * root constants, or the constant used bare), the root is
 * rewritten to a placeholder token and only the remainder is handed to the
 * resolver:
 *
 *   - `WP_CONTENT_DIR` -> `{content_dir}`
 *   - `WP_PLUGIN_DIR`  -> `{plugin_dir}`
 *   - `ABSPATH`        -> `{abspath}`     (already slash-terminated in core,
 *                                          same as real WordPress usage — no
 *                                          slash normalization is added here)
 *
 * The combined key keeps the remainder's own confidence (verified stays
 * verified, a symbol-built remainder degrades to resolved, a partial literal
 * degrades to pattern) — the token is a source-level rewrite, not a lookup,
 * so it does not itself upgrade or downgrade confidence.
 *
 * A root used with nothing after it (`WP_CONTENT_DIR` alone, or a remainder
 * that resolves to an empty string) would name wp-content/the plugins
 * directory/the install root itself — never a meaningful "directory this
 * plugin creates" — so that case is skipped rather than emitted.
 */
final class FilesystemVisitor extends AbstractDetectionVisitor
{
    protected function inspect(Node $node): void
    {
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $this->inspectMethodCall($node);

            return;
        }

        if (!$node instanceof FuncCall || !$node->name instanceof Name) {
            return;
        }

        $fn = strtolower($node->name->toString());

        if ($fn !== 'wp_mkdir_p' && $fn !== 'mkdir') {
            return;
        }

        if ($this->recordFirstClassCallable($node, 'directory', $fn)) {
            return;
        }

        $args = $this->args($node);

        if ($fn === 'wp_mkdir_p') {
            $this->recordDirectory($node, $fn, $this->argValue($args, 0, 'target'));

            return;
        }

        if ($fn === 'mkdir') {
            $this->recordDirectory($node, $fn, $this->argValue($args, 0, 'directory'));
        }
    }

    /**
     * The WP_Filesystem abstraction: security and backup plugins do their
     * filesystem work through `$wp_filesystem->mkdir()` rather than PHP's
     * mkdir(), and the directory it creates is just as persistent. Only the
     * mkdir method is read — put_contents writes files, which are content,
     * not footprint.
     */
    private function inspectMethodCall(MethodCall $node): void
    {
        if (strtolower((string) $node->name) !== 'mkdir' || $node->isFirstClassCallable()) {
            return;
        }

        $args = $this->args($node);
        $this->recordDirectory($node, '$wp_filesystem->mkdir', $this->argValue($args, 0, 'directory'));
    }

    private function recordDirectory(FuncCall|MethodCall $node, string $fn, ?Expr $value): void
    {
        if ($value === null) {
            return;
        }

        $resolution = $this->resolvePath($value);
        if ($resolution === null) {
            return; // the root alone, with no path underneath it — never meaningful
        }

        $this->findings[] = new Finding(
            type: 'directory',
            function: $fn,
            key: $resolution->key(),
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $resolution->raw,
        );
    }
}
