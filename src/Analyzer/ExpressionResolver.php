<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Resolves an expression node to an artifact key and a confidence level (§8) —
 * the heart of the tool.
 *
 * Handles literals, `define()` constants, class constants (`self::X`, `Foo::X`),
 * literal `$this->prop` properties, string concatenation, AND string
 * interpolation (`"{$wpdb->prefix}mylogs"`). Interpolation is deliberately a
 * first-class case: real plugins overwhelmingly interpolate rather than
 * concatenate, so missing it would gut the resolution rate.
 *
 * When only a leading literal survives, it degrades to a `pattern` carrying that
 * prefix; a fully unresolvable expression degrades to `dynamic` with the raw
 * source preserved. It never throws and never guesses.
 */
final class ExpressionResolver
{
    private const NON_STRING_CONSTANTS = ['true', 'false', 'null'];

    public function __construct(
        private readonly SymbolTable $symbols,
        private readonly PrettyPrinter $printer = new PrettyPrinter(),
    ) {
    }

    /**
     * @param string|null $class the enclosing class (fully-qualified), for self:: / $this->
     * @param array<string, string|null> $locals in-scope local variable values
     *        (null = poisoned: known but not a single literal), supplied by the
     *        visitor tracking straight-line assignments in the current function.
     */
    public function resolve(Expr $expr, ?string $class = null, array $locals = []): Resolution
    {
        if ($expr instanceof String_) {
            // An empty literal key is meaningless downstream (WP rejects it);
            // treat it as unresolved rather than a confident empty string.
            return $expr->value === '' ? Resolution::dynamic($this->raw($expr)) : Resolution::verified($expr->value);
        }

        if ($expr instanceof Variable) {
            return $this->resolveLocal($expr, $locals);
        }

        if ($expr instanceof ConstFetch) {
            return $this->resolveConstant($expr);
        }

        if ($expr instanceof ClassConstFetch) {
            return $this->resolveClassConstant($expr, $class);
        }

        if ($expr instanceof PropertyFetch) {
            return $this->resolveProperty($expr, $class);
        }

        if ($expr instanceof StaticPropertyFetch) {
            return $this->resolveStaticProperty($expr, $class);
        }

        if ($expr instanceof Concat) {
            return $this->resolveSegments([$expr->left, $expr->right], $class, $locals, $expr);
        }

        if ($expr instanceof InterpolatedString) {
            return $this->resolveSegments($expr->parts, $class, $locals, $expr);
        }

        return Resolution::dynamic($this->raw($expr));
    }

    /**
     * @param array<string, string|null> $locals
     */
    private function resolveLocal(Variable $expr, array $locals): Resolution
    {
        if (!is_string($expr->name) || !array_key_exists($expr->name, $locals)) {
            return Resolution::dynamic($this->raw($expr));
        }

        $value = $locals[$expr->name];

        return $value !== null ? Resolution::resolved($value) : Resolution::dynamic($this->raw($expr));
    }

    private function resolveConstant(ConstFetch $expr): Resolution
    {
        $name = $expr->name->toString();

        if (in_array(strtolower($name), self::NON_STRING_CONSTANTS, true)) {
            return Resolution::dynamic($this->raw($expr));
        }

        if ($this->symbols->hasConstant($name)) {
            $value = $this->symbols->constant($name);

            return $value !== null ? Resolution::resolved($value) : Resolution::dynamic($this->raw($expr));
        }

        return Resolution::dynamic($this->raw($expr));
    }

    private function resolveClassConstant(ClassConstFetch $expr, ?string $class): Resolution
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return Resolution::dynamic($this->raw($expr));
        }

        $reference = strtolower($expr->class->toString());
        if ($reference === 'self') {
            $target = $class;
        } elseif ($reference === 'static') {
            // static:: is late-bound: the value that runs belongs to whatever class
            // the call was made against. Resolve it only when no subclass in the
            // plugin redefines the constant, which makes the two identical.
            if ($class === null || !$this->symbols->hasLateBoundClassConstant($class, $expr->name->toString())) {
                return Resolution::dynamic($this->raw($expr));
            }

            $value = $this->symbols->lateBoundClassConstant($class, $expr->name->toString());

            return $value !== null ? Resolution::resolved($value) : Resolution::dynamic($this->raw($expr));
        } elseif ($reference === 'parent') {
            $target = $this->symbols->parentOf($class);
            if ($target === null) {
                return Resolution::dynamic($this->raw($expr));
            }
        } else {
            // Fully-qualified by NameResolver, so out-of-tree/aliased classes no
            // longer collide with an in-tree class of the same short name.
            $target = $expr->class->toString();
        }

        if ($target === null) {
            return Resolution::dynamic($this->raw($expr));
        }

        $constant = $expr->name->toString();
        if ($this->symbols->hasClassConstant($target, $constant)) {
            $value = $this->symbols->classConstant($target, $constant);

            return $value !== null ? Resolution::resolved($value) : Resolution::dynamic($this->raw($expr));
        }

        return Resolution::dynamic($this->raw($expr));
    }

    /**
     * `self::$prefix` / `static::$prefix` / `Foo::$prefix`. Static properties are
     * collected alongside instance ones, so the same literal-or-poison rules
     * apply; only the way they are written differs.
     */
    private function resolveStaticProperty(StaticPropertyFetch $expr, ?string $class): Resolution
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof VarLikeIdentifier) {
            return Resolution::dynamic($this->raw($expr));
        }

        $reference = strtolower($expr->class->toString());
        $target = match ($reference) {
            'self', 'static' => $class,
            'parent' => $this->symbols->parentOf($class),
            default => $expr->class->toString(),
        };

        if ($target === null || !$this->symbols->hasProperty($target, $expr->name->toString())) {
            return Resolution::dynamic($this->raw($expr));
        }

        $value = $this->symbols->property($target, $expr->name->toString());

        return $value !== null ? Resolution::resolved($value) : Resolution::dynamic($this->raw($expr));
    }

    private function resolveProperty(PropertyFetch $expr, ?string $class): Resolution
    {
        // $wpdb->prefix / $wpdb->base_prefix are the database table prefix. They
        // resolve to the canonical {prefix} placeholder token (§9), never a
        // hardcoded wp_, so the Index stays correct on custom-prefix sites.
        if (Wpdb::isPrefix($expr)) {
            return Resolution::resolved('{prefix}');
        }

        if (
            $class !== null
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
            && $this->symbols->hasProperty($class, $expr->name->toString())
        ) {
            $value = $this->symbols->property($class, $expr->name->toString());

            return $value !== null ? Resolution::resolved($value) : Resolution::dynamic($this->raw($expr));
        }

        return Resolution::dynamic($this->raw($expr));
    }

    /**
     * Resolve an ordered sequence of concat operands or interpolation parts.
     *
     * Accumulates the full value while every segment resolves; the moment one
     * doesn't, it stops extending the leading prefix. All resolved -> the full
     * key (verified if purely literal, resolved if any symbol was involved);
     * a surviving leading prefix -> a pattern; nothing -> dynamic.
     *
     * @param array<Node> $segments
     */
    private function resolveSegments(array $segments, ?string $class, array $locals, Expr $original): Resolution
    {
        $full = '';
        $prefix = '';
        $prefixOpen = true;
        $sawSymbol = false;
        $sawUnresolved = false;

        foreach ($segments as $segment) {
            if ($segment instanceof InterpolatedStringPart) {
                $full .= $segment->value;
                if ($prefixOpen) {
                    $prefix .= $segment->value;
                }
                continue;
            }

            if (!$segment instanceof Expr) {
                $sawUnresolved = true;
                $prefixOpen = false;
                continue;
            }

            $resolution = $this->resolve($segment, $class, $locals);

            switch ($resolution->confidence) {
                case Finding::CONFIDENCE_VERIFIED:
                    $full .= (string) $resolution->value;
                    if ($prefixOpen) {
                        $prefix .= (string) $resolution->value;
                    }
                    break;

                case Finding::CONFIDENCE_RESOLVED:
                    $sawSymbol = true;
                    $full .= (string) $resolution->value;
                    if ($prefixOpen) {
                        $prefix .= (string) $resolution->value;
                    }
                    break;

                case Finding::CONFIDENCE_PATTERN:
                    // A nested pattern contributes its own prefix, then closes ours.
                    $sawSymbol = true;
                    $sawUnresolved = true;
                    if ($prefixOpen) {
                        $prefix .= (string) $resolution->value;
                        $prefixOpen = false;
                    }
                    break;

                default: // dynamic
                    $sawUnresolved = true;
                    $prefixOpen = false;
                    break;
            }
        }

        if (!$sawUnresolved) {
            return $sawSymbol ? Resolution::resolved($full) : Resolution::verified($full);
        }

        if ($prefix !== '') {
            return Resolution::pattern($prefix, $this->raw($original));
        }

        return Resolution::dynamic($this->raw($original));
    }

    private function raw(Node $node): string
    {
        try {
            return $node instanceof Expr ? $this->printer->prettyPrintExpr($node) : $this->printer->prettyPrint([$node]);
        } catch (\Throwable) {
            return '(unresolved expression)';
        }
    }
}
