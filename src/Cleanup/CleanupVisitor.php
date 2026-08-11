<?php

declare(strict_types=1);

namespace Sediment\Cleanup;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Scalar\MagicConst\Class_ as MagicClass;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use Sediment\Analyzer\Sql\TableStatements;
use Sediment\Analyzer\Visitors\AbstractDetectionVisitor;
use Sediment\Analyzer\Wpdb;

/**
 * Finds the cleanup path (M7): the removal calls a plugin makes to undo what it
 * created, and the `register_uninstall_hook` callbacks that run them. Parsed
 * with the same engine and resolver as detection.
 *
 * Only *confident* removals are recorded — a removal on a `pattern` or `dynamic`
 * key cannot prove any specific create was removed, so it never credits cleanup.
 * The {@see CleanupDiffer} decides which recorded removals actually run on
 * uninstall.
 */
final class CleanupVisitor extends AbstractDetectionVisitor
{
    /** removal function => [key arg index, artifact type, key parameter name] */
    private const REMOVALS = [
        'delete_option'           => [0, 'option', 'option'],
        'delete_site_option'      => [0, 'option', 'option'],
        // The network variant takes the network id first.
        'delete_network_option'   => [1, 'option', 'option'],
        'delete_transient'        => [0, 'transient', 'transient'],
        'delete_site_transient'   => [0, 'transient', 'transient'],
        'wp_clear_scheduled_hook' => [0, 'cron', 'hook'],
        'wp_unschedule_hook'      => [0, 'cron', 'hook'],
        'wp_unschedule_event'     => [1, 'cron', 'hook'],
        'delete_post_meta'        => [1, 'post_meta', 'meta_key'],
        'delete_post_meta_by_key' => [0, 'post_meta', 'post_meta_key'],
        'delete_user_meta'        => [1, 'user_meta', 'meta_key'],
        'delete_term_meta'        => [1, 'term_meta', 'meta_key'],
        'delete_comment_meta'     => [1, 'comment_meta', 'meta_key'],
        'remove_role'             => [0, 'role', 'role'],
        'as_unschedule_all_actions' => [0, 'action', 'hook'],
        'as_unschedule_action'      => [0, 'action', 'hook'],
        'rmdir'                     => [0, 'directory', 'directory'],
    ];

    /**
     * Removals that clear a whole artifact type at once rather than naming a key.
     * flush_rewrite_rules() rebuilds the routing table, which removes every rule
     * the plugin registered, so it credits all of them.
     */
    private const BLANKET_REMOVALS = ['flush_rewrite_rules' => 'rewrite_rule'];

    /** delete_metadata()'s object type comes from its first argument. */
    private const META_OBJECT_TYPES = [
        'post' => 'post_meta', 'user' => 'user_meta', 'term' => 'term_meta', 'comment' => 'comment_meta',
    ];

    /** @var list<array{type: string, key: string, via: string, function: string|null, file: string}> */
    private array $removals = [];

    /** @var list<string> */
    private array $callbacks = [];

    /** @var list<string> lowercased function names called at the top level of uninstall.php */
    private array $uninstallCalls = [];

    /** @var list<array{option: string, default: bool|string|null, function: string|null, file: string, exits: bool, removes: bool, calls: list<string>}> */
    private array $guards = [];

    /** @var array<string, FuncCall> variable name => the option read assigned to it */
    private array $optionReads = [];

    /** @var list<array{type: string, function: string|null, file: string}> */
    private array $blankets = [];

    protected function inspect(Node $node): void
    {
        if ($node instanceof Assign) {
            $this->trackOptionRead($node);

            return;
        }

        if ($node instanceof If_) {
            $this->recordGuard($node);

            return;
        }

        if ($node instanceof FuncCall && $node->name instanceof Name && !$node->isFirstClassCallable()) {
            $function = strtolower($node->name->toString());

            if ($this->currentFunction() === null && $this->inUninstallPhp()) {
                $this->uninstallCalls[] = $function;
            }

            if ($function === 'register_uninstall_hook') {
                $this->recordCallback($node);
            } elseif (isset(self::BLANKET_REMOVALS[$function])) {
                $this->blankets[] = [
                    'type' => self::BLANKET_REMOVALS[$function],
                    'function' => $this->currentFunction(),
                    'file' => $this->file,
                ];
            } elseif ($function === 'delete_metadata') {
                $this->recordDeleteMetadata($node);
            } elseif (isset(self::REMOVALS[$function])) {
                $this->recordRemoval($node, $function);
            }

            return;
        }

        if ($node instanceof MethodCall) {
            $this->recordDropTable($node);
        }
    }

    /**
     * A cleanup routine gated on a stored setting — `if (!get_option('x')) return;`
     * — is the "conditionally clean" case the rubric grades B (§10). In practice
     * that setting defaults to off and sits where no user finds it before hitting
     * Delete, so the plugin is technically clean and practically dirty.
     *
     * The condition may be written any way round — negated, compared to a
     * string, whatever — so the comparison itself is not inspected. What is
     * required is that the `if` actually gates cleanup: either it bails out
     * early (`return`/`exit`), or removals sit inside it. An unrelated
     * `if (get_option('schema') === 'v2') { migrate(); }` in the same file must
     * not cost an otherwise clean plugin its A.
     */
    private function recordGuard(If_ $node): void
    {
        // The option may be read in the condition itself, or a line earlier into
        // a variable the condition tests (`$keep = get_option('x'); if ($keep)`).
        $reads = (new NodeFinder())->findInstanceOf($this->conditions($node), FuncCall::class);
        foreach ($this->conditionVariableReads($node) as $call) {
            $reads[] = $call;
        }

        foreach ($reads as $call) {
            if (!$call instanceof FuncCall || !$call->name instanceof Name || $call->isFirstClassCallable()) {
                continue;
            }

            if (!in_array(strtolower($call->name->toString()), ['get_option', 'get_site_option'], true)) {
                continue;
            }

            $keyValue = $this->argValue($call->getArgs(), 0, 'option');
            if ($keyValue === null) {
                continue;
            }

            $key = $this->resolveKey($keyValue);
            if (!$key->isResolved()) {
                continue; // cannot name the setting, so cannot report it honestly
            }

            $this->guards[] = [
                'option' => (string) $key->value,
                'default' => $this->guardDefault($call),
                'function' => $this->currentFunction(),
                'file' => $this->file,
                // Evidence that this `if` gates cleanup. Whether the calls it
                // makes are themselves part of the uninstall path is something
                // only the differ knows, so it decides.
                'exits' => $this->bailsOut($node),
                'removes' => $this->containsRemoval($node),
                'calls' => $this->calledFunctions($node),
            ];

            return; // one guard per `if` is enough
        }
    }

    /** Remember `$keep = get_option('x');` so a later `if ($keep)` can be read. */
    private function trackOptionRead(Assign $node): void
    {
        if (
            $node->var instanceof Variable
            && is_string($node->var->name)
            && $node->expr instanceof FuncCall
            && $node->expr->name instanceof Name
            && in_array(strtolower($node->expr->name->toString()), ['get_option', 'get_site_option'], true)
        ) {
            $this->optionReads[$node->var->name] = $node->expr;
        }
    }

    /**
     * The conditions of an `if` and of every `elseif` attached to it — a gate is
     * often the second branch (`if (defined(...)) return; elseif (!get_option(...))`).
     *
     * @return list<Node>
     */
    private function conditions(If_ $node): array
    {
        $conditions = [$node->cond];
        foreach ($node->elseifs as $elseif) {
            $conditions[] = $elseif->cond;
        }

        return $conditions;
    }

    /**
     * Option reads assigned to a variable that the condition then tests. Only
     * assignments seen earlier in the traversal are known, which is exactly the
     * code that runs before the `if`.
     *
     * @return list<FuncCall>
     */
    private function conditionVariableReads(If_ $node): array
    {
        $calls = [];
        foreach ((new NodeFinder())->findInstanceOf($this->conditions($node), Variable::class) as $variable) {
            if ($variable instanceof Variable && is_string($variable->name) && isset($this->optionReads[$variable->name])) {
                $calls[] = $this->optionReads[$variable->name];
            }
        }

        return $calls;
    }

    /**
     * Does a branch of this `if` bail out, leaving the removals after it to be
     * skipped? A `return` inside a closure belongs to the closure, not to the
     * uninstall routine, so the search does not descend into one.
     */
    private function bailsOut(If_ $node): bool
    {
        foreach ([$node->stmts, ...array_map(static fn ($e) => $e->stmts, $node->elseifs), $node->else?->stmts ?? []] as $branch) {
            if ($this->hasEarlyExit($branch)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Node> $nodes
     */
    private function hasEarlyExit(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Return_ || $node instanceof Exit_) {
                return true;
            }

            if ($node instanceof FunctionLike) {
                continue; // a closure's return exits the closure, not the routine
            }

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->$name;
                $children = is_array($child) ? $child : [$child];

                if ($this->hasEarlyExit(array_filter($children, static fn ($c): bool => $c instanceof Node))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Does a removal call sit inside this `if`? */
    private function containsRemoval(If_ $node): bool
    {
        return (new NodeFinder())->findFirst($node, static function (Node $n): bool {
            if ($n instanceof FuncCall && $n->name instanceof Name) {
                $name = strtolower($n->name->toString());

                return isset(self::REMOVALS[$name]) || $name === 'delete_metadata';
            }

            return Wpdb::isMethodCall($n, 'query');
        }) !== null;
    }

    /**
     * Functions called inside this `if`. When one of them turns out to be part of
     * the uninstall path, the `if` is gating cleanup indirectly — the common
     * `if (get_option('x')) { my_plugin_cleanup(); }` shape.
     *
     * @return list<string>
     */
    private function calledFunctions(If_ $node): array
    {
        $names = [];
        foreach ((new NodeFinder())->findInstanceOf($node, FuncCall::class) as $call) {
            if ($call instanceof FuncCall && $call->name instanceof Name) {
                $names[] = strtolower($call->name->toString());
            }
        }

        return $names;
    }

    /**
     * get_option()'s second argument is the value returned when the option was
     * never saved — the default that decides what happens on a site where the
     * user never touched the setting. Absent means false.
     */
    private function guardDefault(FuncCall $call): bool|string|null
    {
        $default = $this->argValue($call->getArgs(), 1, 'default');

        if ($default === null) {
            return false;
        }

        if ($default instanceof ConstFetch) {
            return match (strtolower($default->name->toString())) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }

        return $default instanceof String_ ? $default->value : null;
    }

    private function recordCallback(FuncCall $node): void
    {
        $callback = $this->argValue($node->getArgs(), 1, 'callback');
        if ($callback === null) {
            return;
        }

        $identifier = $this->callbackIdentifier($callback);
        if ($identifier !== null) {
            $this->callbacks[] = $identifier;
        }
    }

    private function recordRemoval(FuncCall $node, string $function): void
    {
        [$keyIndex, $type, $parameter] = self::REMOVALS[$function];

        $value = $this->argValue($node->getArgs(), $keyIndex, $parameter);
        if ($value === null) {
            return;
        }

        // wp_clear_scheduled_hook($hook, $args) clears only the events registered
        // with those exact arguments, so it cannot stand for a blanket clear —
        // and Action Scheduler's as_unschedule_all_actions($hook, $args) narrows
        // itself the same way the moment arguments are passed.
        if ($function === 'wp_clear_scheduled_hook' && $this->passesArgs($node->getArgs(), 1, 'args')) {
            return;
        }
        if ($function === 'as_unschedule_all_actions' && $this->passesArgs($node->getArgs(), 1, 'args')) {
            return;
        }

        $resolution = $this->resolveFindingKey($value, $node);

        if (!$resolution->isResolved()) {
            // A plugin that writes through a wrapper usually deletes through one
            // too. Expanding creates but not removals would report those keys as
            // abandoned and drop the plugin's grade for cleanup it does perform,
            // so both ends resolve the same way.
            foreach ($this->wrapperRemovalKeys($value, $node->getStartLine()) as $key) {
                $this->addRemoval($type, $key, $function);
            }

            return; // pattern/dynamic removals cannot credit a specific create
        }

        $this->addRemoval($type, (string) $resolution->value, $function);
    }

    /**
     * delete_metadata($meta_type, $object_id, $meta_key, $meta_value, $delete_all)
     * — the generated uninstall.php uses this form, so credit it when the object
     * type resolves to one of the four known literals.
     */
    private function recordDeleteMetadata(FuncCall $node): void
    {
        $args = $node->getArgs();

        $typeValue = $this->argValue($args, 0, 'meta_type');
        $keyValue = $this->argValue($args, 2, 'meta_key');
        if ($typeValue === null || $keyValue === null) {
            return;
        }

        $type = $this->resolveKey($typeValue);
        $key = $this->resolveKey($keyValue);

        if (!$type->isResolved() || !$key->isResolved() || !isset(self::META_OBJECT_TYPES[(string) $type->value])) {
            return;
        }

        $this->addRemoval(self::META_OBJECT_TYPES[(string) $type->value], (string) $key->value, 'delete_metadata');
    }

    private function recordDropTable(MethodCall $node): void
    {
        if (!Wpdb::isMethodCall($node, 'query')) {
            return;
        }

        $value = $this->argValue($node->getArgs(), 0, 'query');
        if ($value === null) {
            return;
        }

        $resolution = $this->resolveKey($value);
        if ($resolution->value === null) {
            return;
        }

        $truncated = !$resolution->isResolved();
        foreach (TableStatements::dropped($resolution->value, $truncated) as $name) {
            $this->addRemoval('table', $name, 'wpdb::query');
        }
    }

    /**
     * The keys a removal keyed on a wrapper's parameter actually deletes.
     *
     * @return list<string>
     */
    private function wrapperRemovalKeys(Expr $value, int $line): array
    {
        return $this->expansionsAt($line)['literals'] ?? [];
    }

    private function addRemoval(string $type, string $key, string $via): void
    {
        $this->removals[] = [
            'type' => $type,
            'key' => $key,
            'via' => $via,                          // the removal call itself
            'function' => $this->currentFunction(), // the function it sits in
            'file' => $this->file,
        ];
    }

    private function inUninstallPhp(): bool
    {
        return strtolower(str_replace('\\', '/', $this->file)) === 'uninstall.php';
    }

    private function callbackIdentifier(Expr $callback): ?string
    {
        if ($callback instanceof String_) {
            return $callback->value !== '' ? $callback->value : null;
        }

        if ($callback instanceof Array_ && count($callback->items) === 2) {
            return $this->arrayCallbackIdentifier($callback);
        }

        return null;
    }

    private function arrayCallbackIdentifier(Array_ $callback): ?string
    {
        [$classItem, $methodItem] = $callback->items;

        if (
            !$classItem instanceof ArrayItem
            || !$methodItem instanceof ArrayItem
            || !$methodItem->value instanceof String_
        ) {
            return null;
        }

        $class = $this->resolveClassReference($classItem->value);

        return $class !== null ? $class . '::' . $methodItem->value->value : null;
    }

    private function resolveClassReference(Expr $expr): ?string
    {
        if ($expr instanceof MagicClass || ($expr instanceof Variable && $expr->name === 'this')) {
            return $this->currentClass();
        }

        if ($expr instanceof String_) {
            return $expr->value !== '' ? $expr->value : null;
        }

        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name) {
            $reference = strtolower($expr->class->toString());

            return $reference === 'self' || $reference === 'static'
                ? $this->currentClass()
                : $expr->class->toString();
        }

        return null;
    }

    /**
     * @return list<array{type: string, key: string, via: string, function: string|null, file: string}>
     */
    public function removals(): array
    {
        return $this->removals;
    }

    /** @return list<string> */
    public function uninstallCallbacks(): array
    {
        return $this->callbacks;
    }

    /** @return list<string> */
    public function uninstallCalls(): array
    {
        return $this->uninstallCalls;
    }

    /** @return list<array{type: string, function: string|null, file: string}> */
    public function blanketRemovals(): array
    {
        return $this->blankets;
    }

    /** @return list<array{option: string, default: bool|string|null, function: string|null, file: string, exits: bool, removes: bool, calls: list<string>}> */
    public function guards(): array
    {
        return $this->guards;
    }
}
