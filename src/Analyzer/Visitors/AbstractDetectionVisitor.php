<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeVisitorAbstract;
use Sediment\Analyzer\CallSites;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;
use Sediment\Analyzer\WpRoots;

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

    /** @var list<FunctionLike> the function-like nodes behind functionStack */
    private array $functionNodes = [];

    /** @var list<array<string, string|null>> per-function local scopes (null = poisoned) */
    private array $localScopes = [[]];

    private int $loopDepth = 0;

    /** @var list<array<string, true>> per-function parameters never re-bound since entry */
    private array $rawParams = [[]];

    /** @var list<array<string, list<string>>> per-function foreach variables => every value they take */
    private array $loopValues = [[]];

    /** @var list<array<string, array{values: list<string>, keys: list<string>|null}>> per-function locals holding a literal array */
    private array $arrayLocals = [[]];

    /** A literal array longer than this is not expanded element by element. */
    private const MAX_LOOP_VALUES = 100;

    /** @var array<int, array{literals: list<string>, complete: bool}> line => keys its callers supply */
    private array $expansionsByLine = [];

    public function __construct(
        protected readonly string $file,
        protected readonly ExpressionResolver $resolver,
        protected readonly ?CallSites $callSites = null,
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

        if ($node instanceof Foreach_ || $node instanceof For_ || $node instanceof While_ || $node instanceof Do_) {
            $this->loopDepth++;
        }

        if ($node instanceof FunctionLike) {
            $this->localScopes[] = [];
            $this->rawParams[] = [];
            $this->loopValues[] = [];
            $this->arrayLocals[] = [];
            $this->functionStack[] = $this->functionIdentifier($node);
            $this->functionNodes[] = $node;
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

        // And only while `$key` still holds what the caller passed. After
        // `$key = 'acme_' . $key` or `$key = sanitize_key($key)` the callers'
        // literals are no longer the key, and naming them would put another
        // plugin's option into a generated uninstall.php.
        if (!isset($this->rawParams[count($this->rawParams) - 1][$expr->name])) {
            return null;
        }

        return $this->callSites->forParameter($scope, $expr->name);
    }

    final public function leaveNode(Node $node)
    {
        if ($node instanceof Class_) {
            array_pop($this->classStack);
        }

        if ($node instanceof Foreach_ || $node instanceof For_ || $node instanceof While_ || $node instanceof Do_) {
            $this->loopDepth--;
        }

        // After the loop the variable holds only the last element.
        if ($node instanceof Foreach_) {
            foreach ([$node->valueVar, $node->keyVar] as $var) {
                if ($var instanceof Variable && is_string($var->name)) {
                    unset($this->loopValues[count($this->loopValues) - 1][$var->name]);
                }
            }
        }

        if ($node instanceof FunctionLike) {
            array_pop($this->localScopes);
            array_pop($this->rawParams);
            array_pop($this->loopValues);
            array_pop($this->arrayLocals);
            array_pop($this->functionStack);
            array_pop($this->functionNodes);
        }

        return null;
    }

    /** Inspect a single node and record any findings. */
    abstract protected function inspect(Node $node): void;

    /**
     * Record a first-class callable write (`update_option(...)`) as one dynamic
     * finding, reporting whether it was handled.
     *
     * Such a call is a real write whose key only exists at call time; dropping
     * it silently would make the coverage report flatter than the truth. It is
     * recorded as unresolved exactly like any other key that cannot be named,
     * so the resolution rate stays an honest account of what was seen.
     */
    protected function recordFirstClassCallable(CallLike $node, string $type, string $function): bool
    {
        if (!$node->isFirstClassCallable()) {
            return false;
        }

        $this->findings[] = new Finding(
            type: $type,
            function: $function,
            key: null,
            confidence: Finding::CONFIDENCE_DYNAMIC,
            file: $this->file,
            line: $node->getStartLine(),
        );

        return true;
    }

    /**
     * A call's arguments, or none for a first-class callable. `getArgs()`
     * asserts on `strlen(...)`, and with assertions on that one throw used to
     * cost every finding in the file — so visitors read arguments through here.
     *
     * @return list<Arg>
     */
    protected function args(CallLike $node): array
    {
        return $node->isFirstClassCallable() ? [] : array_values($node->getArgs());
    }

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

    /** The innermost function, method, or closure node being traversed. */
    protected function currentFunctionNode(): ?FunctionLike
    {
        return $this->functionNodes === [] ? null : $this->functionNodes[count($this->functionNodes) - 1];
    }

    /**
     * Inside a loop or a closure — where a per-object call plausibly runs over
     * every object (`foreach (get_users() ...)`, `array_map(fn ...)`).
     * ponytail: structural only; a loop over a single id still counts.
     */
    protected function inLoop(): bool
    {
        return $this->loopDepth > 0
            || ($this->functionStack !== [] && $this->functionStack[count($this->functionStack) - 1] === null);
    }

    /** Resolve an expression to a key using the current class and local scope. */
    protected function resolveKey(Expr $expr): Resolution
    {
        return $this->resolver->resolve($expr, $this->currentClass(), $this->currentLocals(), $this->currentFunction());
    }

    /**
     * Resolve a path expression, substituting a leading WP_CONTENT_DIR /
     * WP_PLUGIN_DIR / ABSPATH root with its placeholder token.
     *
     * @return Resolution|null null means: skip entirely (root alone, no path).
     */
    protected function resolvePath(Expr $expr): ?Resolution
    {
        $rooted = WpRoots::split($expr);
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
        $resolution = $this->resolver->resolve($expr, $this->currentClass(), $this->currentLocals(), $this->currentFunction());

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
            $left = $this->resolver->resolve($expr->left, $this->currentClass(), $this->currentLocals(), $this->currentFunction());
            $right = $this->resolver->resolve($expr->right, $this->currentClass(), $this->currentLocals(), $this->currentFunction());

            // Exactly one side must be the caller-supplied part; if both are
            // unknown there is nothing to anchor the key to.
            if ($left->isResolved() && !$right->isResolved()) {
                return self::prefixed((string) $left->value, $this->knownValues($expr->right, $scope), '');
            }

            if ($right->isResolved() && !$left->isResolved()) {
                return self::prefixed('', $this->knownValues($expr->left, $scope), (string) $right->value);
            }

            return null;
        }

        return $this->knownValues($expr, $scope);
    }

    /**
     * Every value a variable takes: each element of the literal array a
     * foreach is walking, or else the keys a wrapper's callers pass.
     *
     * @return array{literals: list<string>, complete: bool}|null
     */
    private function knownValues(Expr $expr, ?string $scope): ?array
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            $values = $this->loopValues[count($this->loopValues) - 1][$expr->name] ?? null;
            if ($values !== null) {
                return ['literals' => $values, 'complete' => true];
            }
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
        } elseif ($node instanceof AssignRef) {
            $this->poisonTarget($node->var);
        } elseif ($node instanceof Param) {
            $this->poisonTarget($node->var); // caller-supplied
            if ($node->var instanceof Variable && is_string($node->var->name)) {
                $this->rawParams[count($this->rawParams) - 1][$node->var->name] = true;
            }
        } elseif ($node instanceof Unset_) {
            foreach ($node->vars as $var) {
                $this->poisonTarget($var);
            }
        } elseif ($node instanceof Foreach_) {
            $this->poisonTarget($node->valueVar);
            if ($node->keyVar !== null) {
                $this->poisonTarget($node->keyVar);
            }
            $this->bindLoopValues($node);
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

        // `$key['x'] = ...` changes `$key` too, so it is no longer the raw parameter.
        // ponytail: by-reference arguments (`preg_match(..., $key)`) and
        // `use (&$key)` closures are not tracked; add when a corpus plugin needs it.
        $target = $node->var;
        while ($target instanceof ArrayDimFetch || $target instanceof PropertyFetch) {
            $target = $target->var;
        }
        if ($target !== $node->var && $target instanceof Variable && is_string($target->name)) {
            $this->poisonLocal($target->name);

            return;
        }

        if (!$node->var instanceof Variable || !is_string($node->var->name)) {
            return;
        }

        $value = null;
        if ($node instanceof Assign) {
            $resolution = $this->resolver->resolve($node->expr, $this->currentClass(), $this->currentLocals(), $this->currentFunction());
            $value = $resolution->isResolved() ? $resolution->value : null;

            if ($node->expr instanceof Array_) {
                $array = $this->literalArray($node->expr);
                $this->bindLocal($node->var->name, null);
                if ($array !== null) {
                    $this->arrayLocals[count($this->arrayLocals) - 1][$node->var->name] = $array;
                }

                return;
            }
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

    /**
     * `foreach (['a', 'b'] as $o)` — or over a local holding that literal, or
     * over `$defaults as $k => $v` — binds the loop variables to every value
     * they take, so `add_option($o)` inside reads as two keys, not one unknown.
     */
    private function bindLoopValues(Foreach_ $node): void
    {
        $array = null;
        if ($node->expr instanceof Array_) {
            $array = $this->literalArray($node->expr);
        } elseif ($node->expr instanceof Variable && is_string($node->expr->name)) {
            $array = $this->arrayLocals[count($this->arrayLocals) - 1][$node->expr->name] ?? null;
        }

        if ($array === null) {
            return;
        }

        $scope = count($this->loopValues) - 1;
        if ($node->valueVar instanceof Variable && is_string($node->valueVar->name) && $array['values'] !== []) {
            $this->loopValues[$scope][$node->valueVar->name] = $array['values'];
        }
        if ($node->keyVar instanceof Variable && is_string($node->keyVar->name) && $array['keys'] !== null) {
            $this->loopValues[$scope][$node->keyVar->name] = $array['keys'];
        }
    }

    /**
     * Every value (and string key) of an array literal, or null when the array
     * is too long or any element cannot be resolved. Values that are not all
     * strings yield an empty value list rather than a partial one.
     *
     * @return array{values: list<string>, keys: list<string>|null}|null
     */
    private function literalArray(Array_ $array): ?array
    {
        if ($array->items === [] || count($array->items) > self::MAX_LOOP_VALUES) {
            return null;
        }

        $values = [];
        $keys = [];
        foreach ($array->items as $item) {
            if ($item->unpack || $item->byRef) {
                return null;
            }

            $value = $this->resolver->resolve($item->value, $this->currentClass(), $this->currentLocals(), $this->currentFunction());
            $values[] = $value->isResolved() ? (string) $value->value : null;

            $key = $item->key !== null ? $this->resolver->resolve($item->key, $this->currentClass(), $this->currentLocals(), $this->currentFunction()) : null;
            $keys[] = $key !== null && $key->isResolved() ? (string) $key->value : null;
        }

        return [
            'values' => in_array(null, $values, true) ? [] : array_values(array_filter($values, 'is_string')),
            'keys' => in_array(null, $keys, true) ? null : array_values(array_filter($keys, 'is_string')),
        ];
    }

    private function poisonLocal(string $name): void
    {
        unset($this->rawParams[count($this->rawParams) - 1][$name]);
        unset($this->loopValues[count($this->loopValues) - 1][$name]);
        unset($this->arrayLocals[count($this->arrayLocals) - 1][$name]);
        $this->localScopes[count($this->localScopes) - 1][$name] = null;
    }

    private function bindLocal(string $name, ?string $value): void
    {
        $index = count($this->localScopes) - 1;
        unset($this->rawParams[$index][$name], $this->loopValues[$index][$name], $this->arrayLocals[$index][$name]);

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
