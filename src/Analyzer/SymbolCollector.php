<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Const_ as ConstStmt;
use PhpParser\Node\Stmt\Property;
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

    public function __construct(
        private readonly SymbolTable $symbols,
        private readonly string $file = '',
    ) {
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Class_) {
            $this->classStack[] = $this->classContext($node);
            $child = ($node->namespacedName ?? null)?->toString() ?? $node->name?->toString();
            if ($child !== null && $node->extends instanceof Name) {
                $this->symbols->addParent($child, $node->extends->toString());
            }

            return null;
        }

        if ($node instanceof FuncCall) {
            $this->collectDefine($node);
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
        if ($node instanceof Class_) {
            array_pop($this->classStack);
        }

        return null;
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : $this->classStack[count($this->classStack) - 1];
    }

    private function classContext(Class_ $node): string
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
