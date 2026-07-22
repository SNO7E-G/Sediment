<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\NodeVisitorAbstract;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Base class for detection visitors. Provides class context (for `self::` and
 * `$this->`), the enclosing function identity (for scoping cleanup to an
 * uninstall callback), and straight-line local-variable tracking.
 *
 * Local tracking is deliberately conservative: a variable only resolves if it is
 * assigned a resolvable value and never re-bound to anything else. Bindings that
 * carry an unknowable value — function parameters, `foreach`, `global`, `static`,
 * `catch`, and list/array destructuring — poison the variable to dynamic, so a
 * single literal assignment elsewhere can never claim it. Safety over coverage.
 *
 * Subclasses implement {@see inspect()} and must not override enterNode/leaveNode.
 */
abstract class AbstractDetectionVisitor extends NodeVisitorAbstract
{
    /** @var list<Finding> */
    protected array $findings = [];

    /** @var list<string> */
    private array $classStack = [];

    /** @var list<string|null> */
    private array $functionStack = [];

    /** @var list<array<string, string|null>> per-function local scopes (null = poisoned) */
    private array $localScopes = [[]];

    public function __construct(
        protected readonly string $file,
        protected readonly ExpressionResolver $resolver,
    ) {
    }

    final public function enterNode(Node $node)
    {
        if ($node instanceof Class_) {
            // Fully-qualified after NameResolver (short name as fallback); anon
            // classes keyed per file+line.
            $this->classStack[] = ($node->namespacedName ?? null)?->toString()
                ?? $node->name?->toString()
                ?? ('@anon@' . $this->file . '@' . $node->getStartLine());
        }

        if ($node instanceof FunctionLike) {
            $this->localScopes[] = [];
            $this->functionStack[] = $this->functionIdentifier($node);
        }

        $this->trackBindings($node);
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

    /**
     * The enclosing named function ("func") or method ("Class::method"), used to
     * scope cleanup to an uninstall callback. Null inside a closure/arrow function.
     */
    protected function currentFunction(): ?string
    {
        return $this->functionStack === [] ? null : $this->functionStack[count($this->functionStack) - 1];
    }

    /** Resolve an expression to a key using the current class and local scope. */
    protected function resolveKey(Expr $expr): Resolution
    {
        return $this->resolver->resolve($expr, $this->currentClass(), $this->currentLocals());
    }

    private function functionIdentifier(FunctionLike $node): ?string
    {
        if ($node instanceof Function_) {
            return ($node->namespacedName ?? null)?->toString() ?? $node->name->toString();
        }

        if ($node instanceof ClassMethod) {
            $class = $this->currentClass();

            return $class !== null ? $class . '::' . $node->name->toString() : $node->name->toString();
        }

        return null; // closure / arrow function
    }

    /** @return array<string, string|null> */
    private function currentLocals(): array
    {
        return $this->localScopes[count($this->localScopes) - 1];
    }

    private function trackBindings(Node $node): void
    {
        if ($node instanceof Assign || $node instanceof AssignOp) {
            $this->recordAssignment($node);
        } elseif ($node instanceof Param) {
            $this->poisonTarget($node->var); // caller-supplied
        } elseif ($node instanceof Foreach_) {
            $this->poisonTarget($node->valueVar);
            if ($node->keyVar !== null) {
                $this->poisonTarget($node->keyVar);
            }
        } elseif ($node instanceof Global_) {
            foreach ($node->vars as $var) {
                $this->poisonTarget($var);
            }
        } elseif ($node instanceof Static_) {
            foreach ($node->vars as $staticVar) {
                $this->poisonTarget($staticVar->var);
            }
        } elseif ($node instanceof Catch_ && $node->var !== null) {
            $this->poisonTarget($node->var);
        }
    }

    private function recordAssignment(Assign|AssignOp $node): void
    {
        // Destructuring targets get unknowable per-element values.
        if ($node->var instanceof List_ || $node->var instanceof Array_) {
            $this->poisonTarget($node->var);

            return;
        }

        if (!$node->var instanceof Variable || !is_string($node->var->name)) {
            return;
        }

        $value = null;
        if ($node instanceof Assign) {
            $resolution = $this->resolver->resolve($node->expr, $this->currentClass(), $this->currentLocals());
            $value = $resolution->isResolved() ? $resolution->value : null;
        }

        $this->bindLocal($node->var->name, $value);
    }

    private function poisonTarget(Node $target): void
    {
        if ($target instanceof Variable && is_string($target->name)) {
            $this->poisonLocal($target->name);
        } elseif ($target instanceof List_ || $target instanceof Array_) {
            foreach ($target->items as $item) {
                if ($item instanceof ArrayItem) {
                    $this->poisonTarget($item->value);
                }
            }
        }
    }

    private function poisonLocal(string $name): void
    {
        $this->localScopes[count($this->localScopes) - 1][$name] = null;
    }

    private function bindLocal(string $name, ?string $value): void
    {
        $index = count($this->localScopes) - 1;

        if (!array_key_exists($name, $this->localScopes[$index])) {
            $this->localScopes[$index][$name] = $value;
        } elseif ($this->localScopes[$index][$name] !== $value) {
            $this->localScopes[$index][$name] = null; // conflicting re-binding
        }
    }

    /**
     * The value of an argument by position OR by PHP 8 named argument.
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
