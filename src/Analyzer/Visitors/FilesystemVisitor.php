<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

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
 * is `ROOT . remainder` (a `Concat`/`InterpolatedString` whose first operand
 * is one of the three root constants, or the constant used bare), the root is
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
    /** root constant name (case-sensitive) => portable placeholder token */
    private const ROOTS = [
        'WP_CONTENT_DIR' => '{content_dir}',
        'WP_PLUGIN_DIR'  => '{plugin_dir}',
        'ABSPATH'        => '{abspath}',
    ];

    protected function inspect(Node $node): void
    {
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

        $args = $node->getArgs();

        if ($fn === 'wp_mkdir_p') {
            $this->recordDirectory($node, $fn, $this->argValue($args, 0, 'target'));

            return;
        }

        if ($fn === 'mkdir') {
            $this->recordDirectory($node, $fn, $this->argValue($args, 0, 'directory'));
        }
    }

    private function recordDirectory(FuncCall $node, string $fn, ?Expr $value): void
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

    /**
     * Resolve a path expression, substituting a leading WP_CONTENT_DIR /
     * WP_PLUGIN_DIR / ABSPATH root with its placeholder token.
     *
     * @return Resolution|null null means: skip entirely (root alone, no path).
     */
    private function resolvePath(Expr $expr): ?Resolution
    {
        $rooted = $this->splitRootedPath($expr);
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
                return null; // token + nothing == the root alone
            }

            $value = $token . $tail->value;

            return $tail->confidence === Finding::CONFIDENCE_VERIFIED
                ? Resolution::verified($value)
                : Resolution::resolved($value);
        }

        if ($tail->confidence === Finding::CONFIDENCE_PATTERN) {
            return Resolution::pattern($token . ($tail->value ?? ''), $this->resolveKey($expr)->raw);
        }

        // Nothing survived past the root — fall back to the plain resolution of
        // the whole expression (which degrades to dynamic) rather than reporting
        // the placeholder alone.
        return $this->resolveKey($expr);
    }

    /**
     * @return array{0: string, 1: Expr|null}|null the placeholder token and the
     *         expression for whatever follows the root (null = nothing follows
     *         it), or null when the expression is not rooted at a known constant.
     */
    private function splitRootedPath(Expr $expr): ?array
    {
        if ($expr instanceof ConstFetch) {
            $token = self::ROOTS[$expr->name->toString()] ?? null;

            return $token !== null ? [$token, null] : null;
        }

        if ($expr instanceof Concat) {
            $leaves = $this->flattenConcat($expr);
            $first = $leaves[0];
            $token = $first instanceof ConstFetch ? (self::ROOTS[$first->name->toString()] ?? null) : null;
            if ($token === null) {
                return null;
            }

            $rest = array_slice($leaves, 1);
            $remainder = array_reduce(
                array_slice($rest, 1),
                static fn (Expr $carry, Expr $next): Expr => new Concat($carry, $next),
                $rest[0],
            );

            return [$token, $remainder];
        }

        if ($expr instanceof InterpolatedString && $expr->parts !== []) {
            $first = $expr->parts[0];
            $token = $first instanceof ConstFetch ? (self::ROOTS[$first->name->toString()] ?? null) : null;
            if ($token === null) {
                return null;
            }

            $rest = array_slice($expr->parts, 1);

            return [$token, $rest === [] ? null : new InterpolatedString($rest)];
        }

        return null;
    }

    /** @return list<Expr> */
    private function flattenConcat(Expr $expr): array
    {
        if (!$expr instanceof Concat) {
            return [$expr];
        }

        return [...$this->flattenConcat($expr->left), ...$this->flattenConcat($expr->right)];
    }
}
