<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Const_ as ConstStmt;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeVisitorAbstract;

/**
 * First pass: harvest literal symbols into a {@see SymbolTable} so the detection
 * pass can resolve keys built from them (§12).
 *
 * Collects `define()` and top-level `const`, class constants, property literals
 * (declared defaults and literal `$this->x = 'literal'` assignments), and class
 * inheritance edges. Crucially, it also *poisons* symbols it cannot trust:
 * non-literal or compound property writes, promoted constructor properties
 * (whose value comes from the caller), and non-literal defaults all record a
 * null so the resolver degrades to `dynamic` instead of trusting a stale literal.
 *
 * Run one collector over every file before any detection, then call
 * {@see SymbolTable::reconcileInheritedProperties()} once.
 */
final class SymbolCollector extends NodeVisitorAbstract
{
    /** @var list<string> stack of enclosing class contexts */
    private array $classStack = [];

    /** @var array<string, Node|null> parameter name => declared type, within the current function */
    private array $parameterTypes = [];

    /** @var array<string, Node|null> "Class::property" => declared type */
    private array $propertyTypes = [];

    public function __construct(
        private readonly SymbolTable $symbols,
        private readonly string $file = '',
        private readonly ?CallSites $callSites = null,
    ) {
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Class_ || $node instanceof Trait_) {
            $this->classStack[] = $this->classContext($node);
            $child = ($node->namespacedName ?? null)?->toString() ?? $node->name?->toString();
            if ($child !== null && $node instanceof Class_ && $node->extends instanceof Name) {
                $this->symbols->addParent($child, $node->extends->toString());
            }

            return null;
        }

        if ($node instanceof TraitUse) {
            // A trait's members compose into the using class, so the lookup
            // edge is what makes `use Keys_Trait; self::SLUG` resolvable. The
            // trait's own declarations are collected because its name is on
            // the context stack while its body is visited.
            $using = $this->currentClass();
            if ($using !== null) {
                foreach ($node->traits as $trait) {
                    $this->symbols->addTraitUse($using, $trait->toString());
                }
            }

            return null;
        }

        if ($node instanceof Function_ || $node instanceof ClassMethod) {
            $this->collectParameters($node);
            $this->collectParameterTypes($node);
        } elseif ($node instanceof Property) {
            $this->collectPropertyTypes($node);
        } elseif ($node instanceof StaticCall || $node instanceof MethodCall) {
            $this->collectCallArguments($node);
        }

        if ($node instanceof FuncCall) {
            $this->collectDefine($node);
            $this->collectCallArguments($node);
            $this->collectStringCallback($node);
        } elseif ($node instanceof Array_) {
            $this->collectArrayCallable($node);
        } elseif ($node instanceof ConstStmt) {
            $this->collectTopLevelConst($node);
        } elseif ($node instanceof ClassConst) {
            $this->collectClassConstants($node);
        } elseif ($node instanceof Property) {
            $this->collectPropertyDefaults($node);
        } elseif ($node instanceof Param) {
            $this->collectPromotedProperty($node);
        } elseif ($node instanceof Assign || $node instanceof AssignOp) {
            $this->collectPropertyWrite($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Class_ || $node instanceof Trait_) {
            array_pop($this->classStack);
        }

        return null;
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : $this->classStack[count($this->classStack) - 1];
    }

    private function classContext(Class_|Trait_ $node): string
    {
        // Fully-qualified after NameResolver (falling back to the short name if it
        // has not run); anonymous classes get a per-file, per-line key so two
        // anon classes never share a symbol bucket across files.
        return ($node->namespacedName ?? null)?->toString()
            ?? $node->name?->toString()
            ?? ('@anon@' . $this->file . '@' . $node->getStartLine());
    }

    private static function literalOrNull(Node $value): ?string
    {
        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * Record a function's parameter names, so a write keyed on `$key` inside it
     * can be matched to the argument position its callers fill.
     */
    private function collectParameters(Function_|ClassMethod $node): void
    {
        $callee = $this->calleeName($node);
        if ($callee === null || $this->callSites === null) {
            return;
        }

        $names = [];
        foreach ($node->params as $param) {
            // A variadic parameter collects an unbounded number of arguments, so
            // position no longer identifies a value; leave it out entirely.
            if ($param->variadic || !$param->var instanceof Variable || !is_string($param->var->name)) {
                return;
            }

            $names[] = $param->var->name;
        }

        $this->callSites->declareFunction($callee, $names);
    }

    /**
     * Record the literal arguments at one call site of a user-defined function.
     *
     * Only calls whose target can be named statically are followed: a plain
     * function, `Class::method()`, and `$this->method()`. A call through a
     * variable could be anything at runtime, and guessing at it would put a key
     * on the wrong plugin's artifact.
     */
    private function collectCallArguments(FuncCall|StaticCall|MethodCall $node): void
    {
        if ($this->callSites === null) {
            return;
        }

        if ($node->isFirstClassCallable()) {
            // `$this->put(...)` hands out a handle to the function; whoever
            // holds it can call it with arguments this pass will never see.
            $callee = $this->callTarget($node);
            if ($callee !== null) {
                $this->callSites->markExternallyCallable($callee);
            }

            return;
        }

        $callee = $this->callTarget($node);
        if ($callee === null) {
            return;
        }

        foreach ($node->getArgs() as $index => $arg) {
            // A named or spread argument breaks the position-to-parameter
            // mapping this relies on, so the whole call is treated as unreadable
            // rather than silently mis-attributed.
            if ($arg->name !== null || $arg->unpack) {
                $this->callSites->recordCall($callee, $index, null);

                continue;
            }

            $this->callSites->recordCall($callee, $index, self::literalOrNull($arg->value));
        }
    }

    private function callTarget(FuncCall|StaticCall|MethodCall $node): ?string
    {
        if ($node instanceof FuncCall) {
            return $node->name instanceof Name ? $node->name->toString() : null;
        }

        if (!$node->name instanceof Identifier) {
            return null;
        }

        if ($node instanceof StaticCall) {
            if (!$node->class instanceof Name) {
                return null;
            }

            // `self::` and `static::` mean the class being collected right now.
            $class = in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true)
                ? $this->currentClass()
                : $node->class->toString();

            return $class === null ? null : $class . '::' . $node->name->toString();
        }

        // `$this->method()` resolves against the enclosing class.
        if ($node->var instanceof Variable && $node->var->name === 'this') {
            $class = $this->currentClass();

            return $class === null ? null : $class . '::' . $node->name->toString();
        }

        // `$helper->set(...)` where `$helper` was *declared* with a class type.
        // This reads a type the author wrote down; it does not infer one. The
        // distinction matters — plugins reach their settings layer through a
        // typed property or an injected dependency far more often than through
        // `$this`, and guessing at an untyped variable would risk naming another
        // plugin's artifact.
        $type = $this->declaredType($node->var);

        return $type === null ? null : $type . '::' . $node->name->toString();
    }

    /**
     * Remember the declared types of a function's parameters, including
     * constructor-promoted ones, for the duration of that function.
     */
    private function collectParameterTypes(Function_|ClassMethod $node): void
    {
        $this->parameterTypes = [];

        foreach ($node->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $this->parameterTypes[$param->var->name] = $param->type;

            // A promoted parameter is also a property, and the settings layer is
            // very often injected exactly this way.
            if ($param->flags !== 0 && $this->currentClass() !== null) {
                $this->propertyTypes[$this->currentClass() . '::' . $param->var->name] = $param->type;
            }
        }
    }

    private function collectPropertyTypes(Property $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }

        foreach ($node->props as $prop) {
            /** @var PropertyItem $prop */
            $this->propertyTypes[$class . '::' . $prop->name->toString()] = $node->type;
        }
    }

    /**
     * The class a variable or property was declared to hold, from a type the
     * author wrote. Nullable types (`?Foo`) count; unions and intersections do
     * not, because more than one class means more than one possible target.
     */
    private function declaredType(Node $var): ?string
    {
        if ($var instanceof Variable && is_string($var->name)) {
            return self::className($this->parameterTypes[$var->name] ?? null);
        }

        if (
            $var instanceof PropertyFetch
            && $var->var instanceof Variable
            && $var->var->name === 'this'
            && $var->name instanceof Identifier
        ) {
            return self::className($this->propertyTypes[$this->currentClass() . '::' . $var->name->toString()] ?? null);
        }

        return null;
    }

    private static function className(?Node $type): ?string
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        // A Name here is already fully qualified by NameResolver.
        return $type instanceof Name ? $type->toString() : null;
    }

    private function calleeName(Function_|ClassMethod $node): ?string
    {
        if ($node instanceof Function_) {
            return ($node->namespacedName ?? null)?->toString() ?? $node->name->toString();
        }

        $class = $this->currentClass();

        return $class === null ? null : $class . '::' . $node->name->toString();
    }

    /**
     * WordPress itself calls hook callbacks, so a function handed to a hook by
     * name has call sites this pass cannot see — its recorded callers must not
     * count as complete. Only the callback argument of the registration
     * functions is read: treating every string in a plugin as a possible
     * callable would poison wrappers that merely share a name with one.
     */
    private const HOOK_REGISTRARS = [
        'add_action', 'add_filter', 'add_shortcode',
        'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook',
    ];

    private function collectStringCallback(FuncCall $node): void
    {
        if (
            $this->callSites === null
            || $node->isFirstClassCallable()
            || !$node->name instanceof Name
            || !in_array(strtolower($node->name->toString()), self::HOOK_REGISTRARS, true)
        ) {
            return;
        }

        $callback = $node->getArgs()[1] ?? null;
        if ($callback !== null && $callback->name === null && $callback->value instanceof String_) {
            $this->callSites->markExternallyCallable($callback->value->value);
        }
    }

    /**
     * `[$this, 'method']`, `[Foo::class, 'method']`, `['Foo', 'method']` — the
     * array-callable shape, marked wherever it appears because hooks are far
     * from the only place plugins pass one. A two-string data array that only
     * looks like a callable costs nothing worse than an honest "incomplete".
     */
    private function collectArrayCallable(Array_ $node): void
    {
        if ($this->callSites === null || count($node->items) !== 2) {
            return;
        }

        [$target, $method] = $node->items;
        if (!$target instanceof ArrayItem || !$method instanceof ArrayItem || !$method->value instanceof String_) {
            return;
        }

        $value = $target->value;
        $class = null;
        if ($value instanceof Variable && $value->name === 'this') {
            $class = $this->currentClass();
        } elseif (
            $value instanceof ClassConstFetch
            && $value->class instanceof Name
            && $value->name instanceof Identifier
            && strtolower($value->name->toString()) === 'class'
        ) {
            $reference = strtolower($value->class->toString());
            $class = in_array($reference, ['self', 'static', 'parent'], true)
                ? $this->currentClass()
                : $value->class->toString();
        } elseif ($value instanceof String_) {
            $class = $value->value;
        }

        if ($class !== null) {
            $this->callSites->markExternallyCallable($class . '::' . $method->value->value);
        }
    }

    private function collectDefine(FuncCall $node): void
    {
        if (!$node->name instanceof Name || strtolower($node->name->toString()) !== 'define') {
            return;
        }
        if ($node->isFirstClassCallable()) {
            return;
        }

        $args = $node->getArgs();
        $nameArg = $args[0] ?? null;
        if ($nameArg === null || $nameArg->name !== null || !$nameArg->value instanceof String_) {
            return;
        }

        $valueArg = $args[1] ?? null;
        $value = ($valueArg !== null && $valueArg->name === null) ? self::literalOrNull($valueArg->value) : null;

        $this->symbols->addConstant($nameArg->value->value, $value);
    }

    private function collectTopLevelConst(ConstStmt $node): void
    {
        foreach ($node->consts as $const) {
            /** @var Const_ $const */
            $this->symbols->addConstant($const->name->toString(), self::literalOrNull($const->value));
        }
    }

    private function collectClassConstants(ClassConst $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }

        foreach ($node->consts as $const) {
            /** @var Const_ $const */
            $this->symbols->addClassConstant($class, $const->name->toString(), self::literalOrNull($const->value));
        }
    }

    private function collectPropertyDefaults(Property $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }

        foreach ($node->props as $prop) {
            /** @var PropertyItem $prop */
            if ($prop->default !== null) {
                $this->symbols->addProperty($class, $prop->name->toString(), self::literalOrNull($prop->default));
            }
        }
    }

    private function collectPromotedProperty(Param $node): void
    {
        $class = $this->currentClass();
        if ($class === null || $node->flags === 0 || !$node->var instanceof Variable || !is_string($node->var->name)) {
            return;
        }

        // A promoted property's value comes from the caller — poison it so a key
        // built from it degrades to dynamic rather than trusting the default.
        $this->symbols->addProperty($class, $node->var->name, null);
    }

    private function collectPropertyWrite(Assign|AssignOp $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }

        // A write through `self::$prop` reaches the same symbol as `$this->prop`,
        // so it must poison it the same way — otherwise a literal default survives
        // a dynamic reassignment and resolves to a value that never runs.
        if ($node->var instanceof StaticPropertyFetch) {
            if ($node->var->name instanceof VarLikeIdentifier) {
                $value = ($node instanceof Assign) ? self::literalOrNull($node->expr) : null;
                $this->symbols->addProperty($class, $node->var->name->toString(), $value);
            }

            return;
        }

        if (
            !$node->var instanceof PropertyFetch
            || !$node->var->var instanceof Variable
            || $node->var->var->name !== 'this'
            || !$node->var->name instanceof Identifier
        ) {
            return;
        }

        // Assign with a literal RHS records the literal; anything else (a compound
        // .= , a variable, a call) poisons the property to null.
        $value = ($node instanceof Assign) ? self::literalOrNull($node->expr) : null;

        $this->symbols->addProperty($class, $node->var->name->toString(), $value);
    }
}
