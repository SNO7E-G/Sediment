<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Name;

/**
 * The WordPress root constants, rewritten to portable placeholder tokens (A8's
 * trick generalized). A finding that stores `WP_CONTENT_DIR . '/logs'` as
 * `{content_dir}/logs` survives on installs whose layout differs, exactly the
 * way `$wpdb->prefix` becomes `{prefix}`.
 *
 * Shared by every visitor that reads paths, so a root added here is understood
 * everywhere at once.
 */
final class WpRoots
{
    /** Root constant name (case-sensitive) => portable placeholder token */
    public const MAP = [
        'WP_CONTENT_DIR' => '{content_dir}',
        'WPMU_PLUGIN_DIR' => '{mu_plugins}',
        'WP_PLUGIN_DIR' => '{plugin_dir}',
        'ABSPATH' => '{abspath}',
    ];

    /**
     * Split a path expression into its leading root token and the expression
     * for whatever follows.
     *
     * @return array{0: string, 1: Expr|null}|null the token and the remainder
     *         (null = nothing follows the root), or null when the expression
     *         is not rooted at a known constant.
     */
    public static function split(Expr $expr): ?array
    {
        if ($expr instanceof ConstFetch) {
            $token = self::MAP[$expr->name->toString()] ?? null;

            return $token !== null ? [$token, null] : null;
        }

        if ($expr instanceof Concat) {
            $leaves = self::flatten($expr);
            $first = $leaves[0];
            $token = $first instanceof ConstFetch ? (self::MAP[$first->name->toString()] ?? null) : null;
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
            if (!$first instanceof ConstFetch) {
                return null;
            }
            $token = self::MAP[$first->name->toString()] ?? null;
            if ($token === null) {
                return null;
            }

            $rest = array_slice($expr->parts, 1);
            $remainder = $rest === [] ? null : new InterpolatedString($rest);

            return [$token, $remainder];
        }

        return null;
    }

    /** @return list<Expr> */
    private static function flatten(Expr $expr): array
    {
        if (!$expr instanceof Concat) {
            return [$expr];
        }

        return [...self::flatten($expr->left), ...self::flatten($expr->right)];
    }

    /**
     * A `Name` node for a root constant, for callers building expressions.
     */
    public static function constantFor(string $token): ?string
    {
        return array_search($token, self::MAP, true) ?: null;
    }
}
