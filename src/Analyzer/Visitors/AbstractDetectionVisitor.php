<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
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
use Sediment\Analyzer\CallSites;
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

    /** @var array<int, string|null> line => the function enclosing it */
    private array $scopeByLine = [];

    /** @var array<int, array{literals: list<string>, complete: bool}> line => keys its callers supply */
    private array $expansionsByLine = [];

    public function __construct(
        protected readonly string $file,
        protected readonly ExpressionResolver $resolver,
        protected readonly ?CallSites $callSites = null,
    ) {
    }

    /**
     * The function enclosing a given line, which is what turns a write keyed on
     * `$key` into a question the call-site index can answer.
     *
     * Recorded per line during the traversal because a finding is inspected
     * again once traversal is over, when the function stack is long gone. The
     * first (outermost) node on a line wins, so a statement is attributed to the
     * named function containing it rather than to a closure nested inside.
     */
    public function scopeAt(int $line): ?string
    {
        return $this->scopeByLine[$line] ?? null;
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

        if (!array_key_exists($node->getStartLine(), $this->scopeByLine)) {
            $this->scopeByLine[$node->getStartLine()] = $this->currentFunction();
        }

        $this->trackBindings($node);
        $this->inspect($node);

        return null;
    }

    /**
     * The literal keys a wrapper's parameter receives at its call sites, for a
     * write whose key is simply that parameter.
     *
     * `update_option($key, ...)` inside `Options_Helper::set($key)` says nothing
     * on its own; what the plugin actually creates is whatever its callers pass.
     *
     * @return array{literals: list<string>, complete: bool}|null
     */
    protected function wrapperLiterals(Expr $expr, ?string $scope): ?array
    {
        if ($this->callSites === null || $scope === null) {
            return null;
        }

        // Only a bare `$key` — an expression built *from* a parameter is a
        // different value than the parameter, and substituting one for the other
        // would name an artifact the plugin never wrote.
        if (!$expr instanceof Variable || !is_string($expr->name)) {
            return null;
        }

        return $this->callSites->forParameter($scope, $expr->name);
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

    /**
     * Resolve the *key argument* of a detected call. When the key is a
     * wrapper's parameter, the keys its callers pass are worked out now —
     * while the syntax tree and the enclosing function are both in hand — and
     * stashed under the call's start line for the scan to pick up after the
     * traversal.
     *
     * Stashed by the call, not the argument, because the call's line is what
     * the finding will carry: a multi-line call puts the two on different
     * lines, and the expansion would be lost for exactly the well-formatted
     * plugins wrapper resolution exists for. And only the key argument may
     * stash at all — a second unresolved argument of the same call (a cron
     * recurrence, a meta object type) writing to the same slot would have its
     * callers' values reported as the artifact's names.
     */
    protected function resolveFindingKey(Expr $expr, Node $call): Resolution
    {
        $resolution = $this->resolver->resolve($expr, $this->currentClass(), $this->currentLocals());

        if (!$resolution->isResolved()) {
            $expansion = $this->expandThroughCallers($expr);
            if ($expansion !== null) {
                $this->expansionsByLine[$call->getStartLine()] = $expansion;
            }
        }

        return $resolution;
    }

    /** @return array{literals: list<string>, complete: bool}|null */
    public function expansionsAt(int $line): ?array
    {
        return $this->expansionsByLine[$line] ?? null;
    }

    /**
     * The keys an unresolvable expression takes once the callers are known.
     *
     * Handles a bare parameter (`update_option($key, ...)`) and a parameter
     * joined to something already resolvable (`self::$meta_prefix . $key`), which
     * is how the large plugins in the corpus actually write: a prefix the class
     * knows, plus a name the caller supplies.
     *
     * @return array{literals: list<string>, complete: bool}|null
     */
    private function expandThroughCallers(Expr $expr): ?array
    {
        $scope = $this->currentFunction();

        if ($expr instanceof Concat) {
            $left = $this->resolver->resolve($expr->left, $this->currentClass(), $this->currentLocals());
            $right = $this->resolver->resolve($expr->right, $this->currentClass(), $this->currentLocals());

            // Exactly one side must be the caller-supplied part; if both are
            // unknown there is nothing to anchor the key to.
            if ($left->isResolved() && !$right->isResolved()) {
                return self::prefixed((string) $left->value, $this->wrapperLiterals($expr->right, $scope), '');
            }

            if ($right->isResolved() && !$left->isResolved()) {
                return self::prefixed('', $this->wrapperLiterals($expr->left, $scope), (string) $right->value);
            }

            return null;
        }

        return $this->wrapperLiterals($expr, $scope);
    }

    /**
     * @param array{literals: list<string>, complete: bool}|null $known
     * @return array{literals: list<string>, complete: bool}|null
     */
    private static function prefixed(string $prefix, ?array $known, string $suffix): ?array
    {
        if ($known === null) {
            return null;
        }

        return [
            'literals' => array_map(static fn (string $k): string => $prefix . $k . $suffix, $known['literals']),
            'complete' => $known['complete'],
        ];
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
     * Was a non-empty arguments array passed at this position? An empty array
     * literal counts as no arguments, matching WordPress — and how an event is
     * scheduled decides which call can actually clear it.
     *
     * @param list<Arg> $args
     */
    protected function passesArgs(array $args, int $index, string $parameter): bool
    {
        $value = $this->argValue($args, $index, $parameter);

        if ($value === null) {
            return false;
        }

        return !($value instanceof Array_ && $value->items === []);
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
