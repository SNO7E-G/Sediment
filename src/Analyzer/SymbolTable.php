<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

/**
 * The resolved-symbols index built by {@see SymbolCollector} in the first pass.
 *
 * Resolving `define()` constants, class constants, and literal properties before
 * detection is the single biggest driver of resolution rate (§12).
 *
 * Safety over coverage: a stored value of `null` is a *poison* marker meaning
 * "this symbol exists but its value is not a single knowable literal" — set when
 * the source is non-literal, when two definitions conflict, or when inheritance
 * makes an instance property unreliable. The resolver treats poisoned symbols as
 * `dynamic`. Poisoning is always the safe direction: under-claiming a key can
 * never cause a wrong deletion, over-claiming can.
 *
 * Class names are stored case-insensitively (PHP class names are), keyed by
 * short name. Cross-namespace short-name collisions poison rather than pick a
 * winner.
 */
final class SymbolTable
{
    /** @var array<string, string|null> global constants: name => value|poison */
    private array $constants = [];

    /** @var array<string, string|null> class constants: "class::CONST" => value|poison */
    private array $classConstants = [];

    /** @var array<string, string|null> object properties: "class::prop" => value|poison */
    private array $properties = [];

    /** @var array<string, string> class inheritance: child => parent (both lowercased) */
    private array $parents = [];

    /** @var array<string, list<string>> parent => direct children, built during reconciliation */
    private array $children = [];

    /** @var array<string, list<string>> class => used traits (all lowercased) */
    private array $traitUses = [];

    public function addConstant(string $name, ?string $value): void
    {
        self::merge($this->constants, $name, $value);
    }

    public function addClassConstant(string $class, string $constant, ?string $value): void
    {
        self::merge($this->classConstants, self::classKey($class, $constant), $value);
    }

    public function addProperty(string $class, string $property, ?string $value): void
    {
        self::merge($this->properties, self::classKey($class, $property), $value);
    }

    public function addParent(string $child, string $parent): void
    {
        $this->parents[strtolower($child)] = strtolower($parent);
    }

    public function addTraitUse(string $class, string $trait): void
    {
        if ($class === '') {
            return;
        }

        $this->traitUses[strtolower($class)][] = strtolower($trait);
    }

    public function hasConstant(string $name): bool
    {
        return array_key_exists($name, $this->constants);
    }

    public function constant(string $name): ?string
    {
        return $this->constants[$name] ?? null;
    }

    /** The class a `parent::` reference points at, if it is known. */
    public function parentOf(?string $class): ?string
    {
        return $class === null ? null : ($this->parents[strtolower($class)] ?? null);
    }

    public function hasClassConstant(string $class, string $constant): bool
    {
        return $this->declaringClass($this->classConstants, $class, $constant) !== null;
    }

    public function classConstant(string $class, string $constant): ?string
    {
        $declaring = $this->declaringClass($this->classConstants, $class, $constant);

        return $declaring !== null ? $this->classConstants[$declaring . '::' . $constant] : null;
    }

    public function hasProperty(string $class, string $property): bool
    {
        return $this->declaringClass($this->properties, $class, $property) !== null;
    }

    public function property(string $class, string $property): ?string
    {
        $declaring = $this->declaringClass($this->properties, $class, $property);

        return $declaring !== null ? $this->properties[$declaring . '::' . $property] : null;
    }

    /**
     * A constant reached through `static::`, which is late-bound: the value that
     * runs is the one on whatever class the call was made against. Resolving it is
     * only sound when no subclass in the plugin redefines it.
     */
    public function lateBoundClassConstant(string $class, string $constant): ?string
    {
        if ($this->overriddenBelow($this->classConstants, $class, $constant)) {
            return null;
        }

        return $this->classConstant($class, $constant);
    }

    public function hasLateBoundClassConstant(string $class, string $constant): bool
    {
        return $this->hasClassConstant($class, $constant)
            && !$this->overriddenBelow($this->classConstants, $class, $constant);
    }

    /**
     * The class a member is actually declared on: the class itself, the nearest
     * ancestor that declares it, or any trait in its composition. PHP looks
     * members up the same way — traits compose their members into the class —
     * so a constant or property defined on a base class or used trait resolves
     * for every class that does not redefine it.
     *
     * @param array<string, string|null> $map
     */
    private function declaringClass(array $map, string $class, string $member): ?string
    {
        $stack = [strtolower($class)];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === '' || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            if (array_key_exists($current . '::' . $member, $map)) {
                return $current;
            }

            if (($this->parents[$current] ?? '') !== '') {
                $stack[] = $this->parents[$current];
            }

            foreach ($this->traitUses[$current] ?? [] as $trait) {
                $stack[] = $trait;
            }
        }

        return null;
    }

    /**
     * @param array<string, string|null> $map
     */
    private function overriddenBelow(array $map, string $class, string $member): bool
    {
        $stack = $this->children[strtolower($class)] ?? [];
        $seen = [];

        while ($stack !== []) {
            $descendant = array_pop($stack);
            if (isset($seen[$descendant])) {
                continue;
            }
            $seen[$descendant] = true;

            if (array_key_exists($descendant . '::' . $member, $map)) {
                return true;
            }

            foreach ($this->children[$descendant] ?? [] as $grandchild) {
                $stack[] = $grandchild;
            }
        }

        return false;
    }

    /**
     * Poison every ancestor property that a subclass re-declares. An instance
     * property (`$this->prop`) follows the object, so if any descendant class
     * overrides it the ancestor's literal is not what runs for that subclass.
     * Class constants resolved via `self::` are early-bound and need no such
     * treatment. Call once after all files are collected.
     */
    public function reconcileInheritedProperties(): void
    {
        $children = [];
        foreach ($this->parents as $child => $parent) {
            $children[$parent][] = $child;
        }
        $this->children = $children;

        foreach (array_keys($this->properties) as $key) {
            if ($this->properties[$key] === null) {
                continue;
            }

            [$class, $property] = explode('::', $key, 2);

            if ($this->propertyOverriddenBelow($class, $property, $children)) {
                $this->properties[$key] = null;
            }
        }
    }

    /**
     * @param array<string, list<string>> $children parent => list of direct children
     */
    private function propertyOverriddenBelow(string $class, string $property, array $children): bool
    {
        $stack = $children[$class] ?? [];
        $seen = [];

        while ($stack !== []) {
            $descendant = array_pop($stack);
            if (isset($seen[$descendant])) {
                continue;
            }
            $seen[$descendant] = true;

            if (array_key_exists($descendant . '::' . $property, $this->properties)) {
                return true;
            }

            foreach ($children[$descendant] ?? [] as $grandchild) {
                $stack[] = $grandchild;
            }
        }

        return false;
    }

    private static function classKey(string $class, string $member): string
    {
        return strtolower($class) . '::' . $member;
    }

    /**
     * Merge a symbol definition using poison semantics: a non-literal value or a
     * value that conflicts with an existing literal collapses the entry to null.
     * Once poisoned, an entry stays poisoned.
     *
     * @param array<string, string|null> $map
     */
    private static function merge(array &$map, string $key, ?string $value): void
    {
        if (!array_key_exists($key, $map)) {
            $map[$key] = $value;

            return;
        }

        $existing = $map[$key];
        if ($existing === null) {
            return; // already poisoned
        }

        if ($value === null || $existing !== $value) {
            $map[$key] = null; // non-literal or conflicting redefinition
        }
    }
}
