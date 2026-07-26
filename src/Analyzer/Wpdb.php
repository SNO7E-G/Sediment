<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Recognises the WordPress database handle, in the shapes plugins actually use.
 *
 * Detection is by name, deliberately: `$wpdb` is a global with a fixed name, and
 * plugins that keep their own reference overwhelmingly name it `wpdb` too
 * (`$this->wpdb`, `self::$wpdb`). Requiring the name is what keeps this from
 * claiming some unrelated object's `->query()` is a schema change — an alias
 * under a different name (`$db = $wpdb`) is missed rather than guessed at.
 */
final class Wpdb
{
    private const NAME = 'wpdb';

    /**
     * Is this expression the $wpdb handle?
     *
     * Only three shapes are accepted: the global `$wpdb`, `$this->wpdb`, and
     * `self::$wpdb`. The bound is deliberate — this feeds cleanup crediting,
     * where accepting an unrelated `$logger->wpdb->query(...)` would credit a
     * table drop that never happens. A handle reached any other way is missed
     * rather than assumed.
     */
    public static function isHandle(Node $expr): bool
    {
        if ($expr instanceof Variable) {
            return $expr->name === self::NAME;
        }

        if (!self::named($expr)) {
            return false;
        }

        if ($expr instanceof PropertyFetch) {
            return $expr->var instanceof Variable && $expr->var->name === 'this';
        }

        return $expr instanceof StaticPropertyFetch && $expr->class instanceof Name;
    }

    private static function named(Node $expr): bool
    {
        return ($expr instanceof PropertyFetch || $expr instanceof StaticPropertyFetch)
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === self::NAME;
    }

    /**
     * Is this a call to the named method on the $wpdb handle — e.g. `$wpdb->query()`
     * or `$this->wpdb->query()`? The method name matters: `$wpdb->prepare()` builds
     * SQL without running it, so treating it as an executed statement would credit
     * a drop that never happens.
     */
    public static function isMethodCall(Node $node, string $method): bool
    {
        return $node instanceof MethodCall
            && !$node->isFirstClassCallable()
            && $node->name instanceof Identifier
            && strtolower($node->name->toString()) === strtolower($method)
            && self::isHandle($node->var);
    }

    /** Is this a read of $wpdb->prefix or $wpdb->base_prefix? */
    public static function isPrefix(Expr $expr): bool
    {
        return $expr instanceof PropertyFetch
            && $expr->name instanceof Identifier
            && in_array(strtolower($expr->name->toString()), ['prefix', 'base_prefix'], true)
            && self::isHandle($expr->var);
    }
}
