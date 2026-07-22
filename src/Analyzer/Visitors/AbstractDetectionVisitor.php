<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Base class for detection visitors. Tracks the enclosing class so keys built
 * from `self::CONST` and `$this->prop` resolve correctly, and hands subclasses
 * the resolver and a place to collect findings.
 *
 * Subclasses implement {@see inspect()} and push onto {@see $findings}. They must
 * not override enterNode/leaveNode (class tracking lives here).
 */
abstract class AbstractDetectionVisitor extends NodeVisitorAbstract
{
    /** @var list<Finding> */
    protected array $findings = [];

    /** @var list<string> */
    private array $classStack = [];

    public function __construct(
        protected readonly string $file,
        protected readonly ExpressionResolver $resolver,
    ) {
    }

    final public function enterNode(Node $node)
    {
        if ($node instanceof Class_) {
            // Must match SymbolCollector's key so self:: / $this-> inside an
            // anonymous class resolves against that class and only that class.
            $this->classStack[] = $node->name?->toString() ?? ('@anon@' . $node->getStartLine());
        }

        $this->inspect($node);

        return null;
    }

    final public function leaveNode(Node $node)
    {
        if ($node instanceof Class_) {
            array_pop($this->classStack);
        }

        return null;
    }

    /** Inspect a single node and record any findings. */
    abstract protected function inspect(Node $node): void;

    /** @return list<Finding> */
    public function findings(): array
    {
        return $this->findings;
    }

    protected function currentClass(): ?string
    {
        return $this->classStack === [] ? null : $this->classStack[count($this->classStack) - 1];
    }

    /** Resolve an expression to a key using the current class context. */
    protected function resolveKey(Expr $expr): Resolution
    {
        return $this->resolver->resolve($expr, $this->currentClass());
    }

    /**
     * The value of an argument by position OR by PHP 8 named argument, so that
     * `add_option(option: 'x')` and `add_option('x')` read the same. Returns null
     * if neither is present.
     *
     * @param list<Arg> $args
     */
    protected function argValue(array $args, int $index, string $name): ?Expr
    {
        $arg = $this->positionalArg($args, $index) ?? $this->namedArg($args, $name);

        return $arg?->value;
    }

    /** @param list<Arg> $args */
    protected function positionalArg(array $args, int $index): ?Arg
    {
        $arg = $args[$index] ?? null;

        return ($arg !== null && $arg->name === null) ? $arg : null;
    }

    /** @param list<Arg> $args */
    protected function namedArg(array $args, string $name): ?Arg
    {
        foreach ($args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === $name) {
                return $arg;
            }
        }

        return null;
    }
}
