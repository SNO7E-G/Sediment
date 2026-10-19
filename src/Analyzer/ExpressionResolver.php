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
use PhpParser\Node\Scalar\MagicConst;
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

    /**
     * A concatenation or interpolation with more pieces than this is dynamic.
     * Hand-written keys have a handful; past this it is a generated SQL builder
     * or minified code, and following it buys nothing but time.
     */
    public const MAX_SEGMENTS = 256;

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
     * @param string|null $function the enclosing function or method ("Class::method"),
     *        for __FUNCTION__ / __METHOD__
     */
    public function resolve(Expr $expr, ?string $class = null, array $locals = [], ?string $function = null): Resolution
    {
        $resolution = $this->resolveValue($expr, $class, $locals, $function);

        // The raw source is printed once, for the expression asked about. Nested
        // segments never need theirs, and printing at every level of a long
        // concatenation made resolving it quadratic.
        return $resolution->isResolved()
            ? $resolution
            : new Resolution($resolution->confidence, $resolution->value, $this->raw($expr));
    }

    /**
     * The operands of a concatenation chain, left to right, however it nests —
     * or null past {@see MAX_SEGMENTS}. Walked with a stack, not recursion, so a
     * generated thousand-term chain costs one linear pass.
     *
     * @return list<Expr>|null
     */
    public static function concatOperands(Concat $concat): ?array
    {
        $operands = [];
        $pending = [$concat];

        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof Concat) {
                $pending[] = $node->right;
                $pending[] = $node->left;
                continue;
            }

            if (count($operands) === self::MAX_SEGMENTS) {
                return null;
            }
            $operands[] = $node;
        }

        return $operands;
    }

    /**
     * @param array<string, string|null> $locals
     */
    private function resolveValue(Expr $expr, ?string $class, array $locals, ?string $function): Resolution
    {
        if ($expr instanceof String_) {
            // An empty literal key is meaningless downstream (WP rejects it);
            // treat it as unresolved rather than a confident empty string.
            return $expr->value === '' ? Resolution::dynamic() : Resolution::verified($expr->value);
        }

        if ($expr instanceof MagicConst) {
            return $this->resolveMagicConstant($expr, $class, $function);
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
            $operands = self::concatOperands($expr);

            return $operands !== null ? $this->resolveSegments($operands, $class, $locals, $function) : Resolution::dynamic();
        }

        if ($expr instanceof InterpolatedString) {
            return count($expr->parts) <= self::MAX_SEGMENTS
                ? $this->resolveSegments($expr->parts, $class, $locals, $function)
                : Resolution::dynamic();
        }

        return Resolution::dynamic();
    }

    /**
     * @param array<string, string|null> $locals
     */
    private function resolveLocal(Variable $expr, array $locals): Resolution
    {
        if (!is_string($expr->name) || !array_key_exists($expr->name, $locals)) {
            return Resolution::dynamic();
        }

        $value = $locals[$expr->name];

        return $value !== null ? Resolution::resolved($value) : Resolution::dynamic();
    }

    private function resolveConstant(ConstFetch $expr): Resolution
    {
        $name = $expr->name->toString();

        if (in_array(strtolower($name), self::NON_STRING_CONSTANTS, true)) {
            return Resolution::dynamic();
        }

        if ($this->symbols->hasConstant($name)) {
            $value = $this->symbols->constant($name);

            return $value !== null ? Resolution::resolved($value) : Resolution::dynamic();
        }

        return Resolution::dynamic();
    }

    /**
     * __CLASS__, __METHOD__, and __FUNCTION__ are real strings at runtime and
     * a common key seed in class-organized plugins (`add_option(__CLASS__ . ...)`)
     * — resolving them upgrades honest coverage, not guesswork. Anything whose
     * value depends on the call site (__NAMESPACE__, __DIR__, __LINE__) stays
     * unresolved: it is rarely a key, and the enclosing context here is the
     * declaration site, not where code happens to run.
     */
    private function resolveMagicConstant(MagicConst $expr, ?string $class, ?string $function): Resolution
    {
        $value = match (true) {
            $expr instanceof MagicConst\Class_ => $class,
            $expr instanceof MagicConst\Method => $function,
            // Inside a method __FUNCTION__ is just the method name.
            $expr instanceof MagicConst\Function_ => $function !== null && str_contains($function, '::')
                ? substr($function, strrpos($function, '::') + 2)
                : $function,
            default => null,
        };

        return $value !== null && $value !== ''
            ? Resolution::resolved($value)
            : Resolution::dynamic();
    }

    private function resolveClassConstant(ClassConstFetch $expr, ?string $class): Resolution
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return Resolution::dynamic();
        }

        $reference = strtolower($expr->class->toString());

        // `Foo::class` / `self::class` is the class's own name — a literal at
        // heart, and a common option-key seed. static::class stays dynamic:
        // which subclass answers is decided at runtime.
        if ($expr->name->toLowerString() === 'class') {
            $name = match ($reference) {
                'self' => $class,
                'static', 'parent' => null,
                default => $expr->class->toString(),
            };

            return $name !== null && $name !== ''
                ? Resolution::resolved($name)
                : Resolution::dynamic();
        }

        if ($reference === 'self') {
            $target = $class;
        } elseif ($reference === 'static') {
            // static:: is late-bound: the value that runs belongs to whatever class
            // the call was made against. Resolve it only when no subclass in the
            // plugin redefines the constant, which makes the two identical.
            if ($class === null || !$this->symbols->hasLateBoundClassConstant($class, $expr->name->toString())) {
                return Resolution::dynamic();
            }

            $value = $this->symbols->lateBoundClassConstant($class, $expr->name->toString());

            return $value !== null ? Resolution::resolved($value) : Resolution::dynamic();
        } elseif ($reference === 'parent') {
            $target = $this->symbols->parentOf($class);
            if ($target === null) {
                return Resolution::dynamic();
            }
        } else {
            // Fully-qualified by NameResolver, so out-of-tree/aliased classes no
            // longer collide with an in-tree class of the same short name.
            $target = $expr->class->toString();
        }

        if ($target === null) {
            return Resolution::dynamic();
        }

        $constant = $expr->name->toString();
        if ($this->symbols->hasClassConstant($target, $constant)) {
            $value = $this->symbols->classConstant($target, $constant);

            return $value !== null ? Resolution::resolved($value) : Resolution::dynamic();
        }

        return Resolution::dynamic();
    }

    /**
     * `self::$prefix` / `static::$prefix` / `Foo::$prefix`. Static properties are
     * collected alongside instance ones, so the same literal-or-poison rules
     * apply; only the way they are written differs.
     */
    private function resolveStaticProperty(StaticPropertyFetch $expr, ?string $class): Resolution
    {
        if (!$expr->class instanceof Name || !$expr->name instanceof VarLikeIdentifier) {
            return Resolution::dynamic();
        }

        $reference = strtolower($expr->class->toString());
        $target = match ($reference) {
            'self', 'static' => $class,
            'parent' => $this->symbols->parentOf($class),
            default => $expr->class->toString(),
        };

        if ($target === null || !$this->symbols->hasProperty($target, $expr->name->toString())) {
            return Resolution::dynamic();
        }

        $value = $this->symbols->property($target, $expr->name->toString());

        return $value !== null ? Resolution::resolved($value) : Resolution::dynamic();
    }

    private function resolveProperty(PropertyFetch $expr, ?string $class): Resolution
    {
        // $wpdb->prefix / $wpdb->base_prefix are the database table prefix. They
        // resolve to the canonical {prefix} placeholder token (§9), never a
        // hardcoded wp_, so the Index stays correct on custom-prefix sites.
        if (Wpdb::isPrefix($expr)) {
            return Resolution::resolved('{prefix}');
        }

        $coreTable = Wpdb::coreTable($expr);
        if ($coreTable !== null) {
            return Resolution::resolved($coreTable);
        }

        if (
            $class !== null
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
            && $this->symbols->hasProperty($class, $expr->name->toString())
        ) {
            $value = $this->symbols->property($class, $expr->name->toString());

            return $value !== null ? Resolution::resolved($value) : Resolution::dynamic();
        }

        return Resolution::dynamic();
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
     * @param array<string, string|null> $locals
     */
    private function resolveSegments(array $segments, ?string $class, array $locals, ?string $function): Resolution
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

            $resolution = $this->resolveValue($segment, $class, $locals, $function);

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
            return Resolution::pattern($prefix);
        }

        return Resolution::dynamic();
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
