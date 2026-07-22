<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Base class for detection visitors. Provides three things subclasses rely on:
 *
 *  - class context, so `self::CONST` and `$this->prop` resolve to the right class;
 *  - straight-line local-variable tracking within a function, so the common
 *    `$sql = "…"; dbDelta($sql);` pattern resolves. Tracking is scoped per
 *    function and poisons a variable to dynamic on any conflicting or non-literal
 *    reassignment — safety over coverage;
 *  - the resolver, plus named-argument-safe argument access.
 *
 * Subclasses implement {@see inspect()} and must not override enterNode/leaveNode.
 */
abstract class AbstractDetectionVisitor extends NodeVisitorAbstract
{
    /** @var list<Finding> */
    protected array $findings = [];

    /** @var list<string> */
    private array $classStack = [];

    /** @var list<array<string, string|null>> stack of per-function local scopes */
    private array $localScopes = [[]];

    /** @var list<string|null> stack of enclosing function identifiers (null = anonymous) */
    private array $functionStack = [];

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

        if ($node instanceof FunctionLike) {
            $this->localScopes[] = [];
            $this->functionStack[] = $this->functionIdentifier($node);
        }

        if ($node instanceof Assign || $node instanceof AssignOp) {
            $this->recordLocalAssignment($node);
        }

        $this->inspect($node);

        return null;
    }

    final public function leaveNode(Node $node)
    {
        if ($node instanceof Class_) {
            array_pop($this->classStack);
        }

        if ($node instanceof FunctionLike) {
            array_pop($this->localScopes);
            array_pop($this->functionStack);
        }

        return null;
    }

    /**
     * The enclosing named function ("func") or method ("Class::method"), used to
     * scope cleanup detection to an uninstall callback. Null inside a closure or
     * arrow function.
     */
    protected function currentFunction(): ?string
    {
        return $this->functionStack === [] ? null : $this->functionStack[count($this->functionStack) - 1];
    }

    private function functionIdentifier(FunctionLike $node): ?string
    {
        if ($node instanceof Function_) {
            return $node->name->toString();
        }

        if ($node instanceof ClassMethod) {
            $class = $this->currentClass();

            return $class !== null ? $class . '::' . $node->name->toString() : $node->name->toString();
        }

        return null; // closure / arrow function
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

    /** Resolve an expression to a key using the current class and local scope. */
    protected function resolveKey(Expr $expr): Resolution
    {
        return $this->resolver->resolve($expr, $this->currentClass(), $this->currentLocals());
    }

    /** @return array<string, string|null> */
    private function currentLocals(): array
    {
        return $this->localScopes[count($this->localScopes) - 1];
    }

    private function recordLocalAssignment(Assign|AssignOp $node): void
    {
        if (!$node->var instanceof Variable || !is_string($node->var->name)) {
            return;
        }

        // A plain literal/resolvable assignment records its value; a compound
        // assignment (.=) or an unresolvable RHS records null (poison).
        $value = null;
        if ($node instanceof Assign) {
            $resolution = $this->resolver->resolve($node->expr, $this->currentClass(), $this->currentLocals());
            $value = $resolution->isResolved() ? $resolution->value : null;
        }

        $index = count($this->localScopes) - 1;
        $name = $node->var->name;

        if (!array_key_exists($name, $this->localScopes[$index])) {
            $this->localScopes[$index][$name] = $value;
        } elseif ($this->localScopes[$index][$name] !== $value) {
            $this->localScopes[$index][$name] = null; // conflicting reassignment
        }
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
