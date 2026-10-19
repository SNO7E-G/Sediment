<?php

declare(strict_types=1);

namespace Sediment\Cleanup;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Class_ as MagicClass;
use PhpParser\Node\Scalar\MagicConst\Dir as MagicDir;
use PhpParser\Node\Scalar\MagicConst\File as MagicFile;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use Sediment\Analyzer\Sql\TableStatements;
use Sediment\Analyzer\Visitors\AbstractDetectionVisitor;
use Sediment\Analyzer\Visitors\DropinVisitor;
use Sediment\Analyzer\Wpdb;
use Sediment\Analyzer\WpRoots;

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

    /** Removals scoped to one object id, which clear a key everywhere only when looped. */
    private const SINGLE_OBJECT_META = [
        'delete_post_meta' => true,
        'delete_user_meta' => true,
        'delete_term_meta' => true,
        'delete_comment_meta' => true,
    ];

    /** Words that name a "remove my data on uninstall" setting. */
    private const REMOVAL_SETTING = '/(delete|remove|clean|purge|wipe|keep|preserve|retain)[\w-]*(data|settings|options|tables|uninstall|all)|uninstall[\w-]*(data|delete|remove|clean)/i';

    /** delete_metadata()'s object type comes from its first argument. */
    private const META_OBJECT_TYPES = [
        'post' => 'post_meta', 'user' => 'user_meta', 'term' => 'term_meta', 'comment' => 'comment_meta',
    ];

    /** @var list<array{type: string, key: string, via: string, function: string|null, file: string}> */
    private array $removals = [];

    /** @var list<string> */
    private array $callbacks = [];

    /** @var list<string> register_deactivation_hook callbacks */
    private array $deactivationCallbacks = [];

    /** @var array<string, array{from: string|null, file: string, to: string}> call edges, deduplicated; from = null at file top level */
    private array $calls = [];

    /** @var array<int, string> spl_object_id of a closure registered as a hook => its identifier */
    private array $closureIds = [];

    /** @var list<array{option: string|null, default: bool|string|null, function: string|null, file: string, exits: bool, removes: bool, calls: list<string>}> */
    private array $guards = [];

    /** @var array<string, FuncCall> variable name => the option read assigned to it */
    private array $optionReads = [];

    /** @var list<array{type: string, function: string|null, file: string}> */
    private array $blankets = [];

    /** @var list<array{from: string, to: string}> require/include edges, project-relative paths */
    private array $requires = [];

    protected function inspect(Node $node): void
    {
        if ($node instanceof Include_) {
            $this->recordRequire($node);

            return;
        }

        if ($node instanceof Assign) {
            $this->trackOptionRead($node);

            return;
        }

        if ($node instanceof If_) {
            $this->recordGuard($node);

            return;
        }

        $this->recordCallEdge($node);

        if ($node instanceof FuncCall && $node->name instanceof Name && !$node->isFirstClassCallable()) {
            $function = strtolower($node->name->toString());

            if ($function === 'register_uninstall_hook') {
                $this->recordCallback($node, $this->callbacks);
            } elseif ($function === 'register_deactivation_hook') {
                $this->recordCallback($node, $this->deactivationCallbacks);
            } elseif (isset(self::BLANKET_REMOVALS[$function])) {
                $this->blankets[] = [
                    'type' => self::BLANKET_REMOVALS[$function],
                    'function' => $this->scope(),
                    'file' => $this->file,
                ];
            } elseif ($function === 'delete_metadata') {
                $this->recordDeleteMetadata($node);
            } elseif (isset(self::REMOVALS[$function])) {
                $this->recordRemoval($node, $function);
            } elseif ($function === 'unlink' || $function === 'wp_delete_file') {
                // Drop-ins and mu-plugins are the two file types whose removal
                // is unambiguously cleanup; classify by path like delete_metadata
                // classifies by its object-type argument.
                $this->recordFileRemovalNode($node, $function, $this->argValue($this->args($node), 0, 'path'));
            }

            return;
        }

        if ($node instanceof MethodCall) {
            $this->recordDropTable($node);
            $this->recordFileRemoval($node);
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

            $keyValue = $this->argValue($this->args($call), 0, 'option');
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
                'function' => $this->scope(),
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

        // Not a get_option() read — but plugins also gate through their own
        // settings layer: `if (!Settings::get('remove_data')) return;`,
        // `if ($opts['delete_on_uninstall'])`. Counted only when the condition
        // names a data-removal setting, so an `if (is_multisite())` around the
        // removals never turns a clean plugin into a B.
        $setting = $this->namedRemovalSetting($node);
        if ($setting !== null) {
            $this->guards[] = [
                'option' => $setting === '' ? null : $setting,
                'default' => null,
                'function' => $this->scope(),
                'file' => $this->file,
                'exits' => $this->bailsOut($node),
                'removes' => $this->containsRemoval($node),
                'calls' => $this->calledFunctions($node),
            ];
        }
    }

    /**
     * The setting an `if` reads to decide whether data is removed: the string
     * naming it when there is one ('' when the words appear only in a name),
     * or null when the condition does not look like a data-removal setting.
     * ponytail: a keyword heuristic over the condition's strings and names;
     * a setting called something else entirely is missed (under-reports B).
     */
    private function namedRemovalSetting(If_ $node): ?string
    {
        $words = [];
        $literal = null;
        foreach ((new NodeFinder())->find($this->conditions($node), static fn (Node $n): bool => $n instanceof String_ || $n instanceof Name || $n instanceof Identifier || $n instanceof Variable) as $n) {
            $word = match (true) {
                $n instanceof String_ => $n->value,
                $n instanceof Variable => is_string($n->name) ? $n->name : '',
                $n instanceof Name, $n instanceof Identifier => $n->toString(),
                default => '',
            };

            // The standard entry guard, or a check that code exists
            // (`function_exists('acme_delete_data')`) — neither is a setting.
            // `defined('WC_REMOVE_ALL_DATA')` stays: a wp-config constant is
            // exactly how some plugins gate their teardown.
            if (stripos($word, 'WP_UNINSTALL_PLUGIN') !== false
                || in_array(strtolower($word), ['function_exists', 'class_exists', 'method_exists', 'is_callable'], true)) {
                return null;
            }

            $words[] = $word;
            if ($literal === null && $n instanceof String_ && preg_match(self::REMOVAL_SETTING, $word) === 1) {
                $literal = $word;
            }
        }

        if ($literal !== null) {
            return $literal;
        }

        return preg_match(self::REMOVAL_SETTING, implode(' ', $words)) === 1 ? '' : null;
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
        foreach ((new NodeFinder())->find($node, static fn (Node $n): bool => $n instanceof Expr\CallLike) as $call) {
            $target = $this->callTarget($call);
            if ($target !== null) {
                $names[] = strtolower($target);
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
        $default = $this->argValue($this->args($call), 1, 'default');

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

    /** @param list<string> $into */
    private function recordCallback(FuncCall $node, array &$into): void
    {
        $callback = $this->argValue($this->args($node), 1, 'callback');
        if ($callback === null) {
            return;
        }

        // A closure has no name to call it by, so it gets one here: the hook
        // registration is entered before its argument, so the id is in place
        // by the time the closure's own removals are recorded.
        if ($callback instanceof Closure || $callback instanceof ArrowFunction) {
            $identifier = sprintf('{closure}@%s:%d', $this->file, $callback->getStartLine());
            $this->closureIds[spl_object_id($callback)] = $identifier;
        } else {
            $identifier = $this->callbackIdentifier($callback);
        }

        if ($identifier !== null) {
            $into[] = $identifier;
        }
    }

    /**
     * Where recorded code sits: the named function or method, a closure
     * registered as a hook callback, or null for top-level code (and any
     * other closure, which runs wherever its enclosing code runs).
     */
    private function scope(): ?string
    {
        $node = $this->currentFunctionNode();

        return $this->currentFunction()
            ?? ($node !== null ? $this->closureIds[spl_object_id($node)] ?? null : null);
    }

    /**
     * One edge of the call graph the differ walks to decide what runs on
     * uninstall: a teardown is routinely `Uninstaller::run()` calling helpers
     * that call delete_option(). Only targets nameable without guessing are
     * recorded — a function, `Class::method()`, `self::`/`static::`, `$this->`,
     * and `(new Class)->method()`; a call on any other receiver is skipped,
     * which can only under-credit.
     * ponytail: `$this->m()` resolves to the current class, not the parent
     * that may declare m(); walk SymbolTable parents if that gap shows up.
     */
    private function recordCallEdge(Node $node): void
    {
        $to = $this->callTarget($node);
        if ($to === null) {
            return;
        }

        // Deduplicated: a large plugin makes hundreds of thousands of calls, and
        // the graph needs each edge once. The file only matters for top-level
        // calls, which are roots when the file runs on uninstall.
        $from = $this->scope();
        $file = $from === null ? $this->file : '';
        $this->calls[$from . "\0" . $file . "\0" . strtolower($to)] = ['from' => $from, 'file' => $file, 'to' => $to];
    }

    private function callTarget(Node $node): ?string
    {
        $to = null;

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $to = $node->name->toString();
        } elseif ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $class = strtolower($node->class->toString()) === 'self' || strtolower($node->class->toString()) === 'static'
                ? $this->currentClass()
                : (strtolower($node->class->toString()) === 'parent' ? null : $node->class->toString());
            $to = $class !== null ? $class . '::' . $node->name->toString() : null;
        } elseif ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $class = match (true) {
                $node->var instanceof Variable && $node->var->name === 'this' => $this->currentClass(),
                $node->var instanceof New_ && $node->var->class instanceof Name => $node->var->class->toString(),
                default => null,
            };
            $to = $class !== null ? $class . '::' . $node->name->toString() : null;
        }

        return $to;
    }

    private function recordRemoval(FuncCall $node, string $function): void
    {
        [$keyIndex, $type, $parameter] = self::REMOVALS[$function];

        $value = $this->argValue($this->args($node), $keyIndex, $parameter);
        if ($value === null) {
            return;
        }

        // wp_clear_scheduled_hook($hook, $args) clears only the events registered
        // with those exact arguments, so it cannot stand for a blanket clear —
        // and Action Scheduler's as_unschedule_all_actions($hook, $args) narrows
        // itself the same way the moment arguments are passed.
        if ($function === 'wp_clear_scheduled_hook' && $this->passesArgs($this->args($node), 1, 'args')) {
            return;
        }
        if ($function === 'as_unschedule_all_actions' && $this->passesArgs($this->args($node), 1, 'args')) {
            return;
        }

        // delete_post_meta($id, 'k') removes the key from ONE object. Credit it
        // only where it plausibly runs over every object — inside a loop or a
        // callback — never as a single call that leaves the rest behind.
        if (isset(self::SINGLE_OBJECT_META[$function]) && !$this->inLoop()) {
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
        $args = $this->args($node);

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

        // Without $delete_all this removes the key from one object only.
        $deleteAll = $this->argValue($args, 4, 'delete_all');
        $isDeleteAll = $deleteAll instanceof ConstFetch && strtolower($deleteAll->name->toString()) === 'true';
        if (!$isDeleteAll && !$this->inLoop()) {
            return;
        }

        $this->addRemoval(self::META_OBJECT_TYPES[(string) $type->value], (string) $key->value, 'delete_metadata');
    }

    private function recordDropTable(MethodCall $node): void
    {
        if (!Wpdb::isMethodCall($node, 'query')) {
            return;
        }

        $value = $this->argValue($this->args($node), 0, 'query');
        if ($value === null) {
            return;
        }

        // The bulk teardown most plugins write goes through prepare():
        // $wpdb->query($wpdb->prepare("DELETE ... LIKE %s", $wpdb->esc_like('acme_') . '%')).
        $sql = Wpdb::isMethodCall($value, 'prepare') && $value instanceof MethodCall
            ? $this->preparedSql($value)
            : null;

        $truncated = false;
        if ($sql === null) {
            $resolution = $this->resolveKey($value);
            if ($resolution->value === null) {
                return;
            }

            $sql = $resolution->value;
            $truncated = !$resolution->isResolved();
        }

        foreach (TableStatements::dropped($sql, $truncated) as $name) {
            $this->addRemoval('table', $name, 'wpdb::query');
        }

        if ($truncated) {
            return; // a DELETE cut short could have lost a narrowing AND
        }

        foreach (TableStatements::deletedRows($sql) as [$type, $pattern, $network]) {
            $this->addRemoval($type, $pattern, $network ? CleanupDiffer::LIKE_NETWORK : CleanupDiffer::LIKE);
        }
    }

    /**
     * The SQL a fully-resolvable `$wpdb->prepare()` produces: `%s` placeholders
     * filled with their quoted arguments, `%d` with numbers, `%%` collapsed.
     * Null when any piece cannot be resolved — a guessed argument could widen
     * a DELETE into crediting keys it never touches.
     */
    private function preparedSql(MethodCall $prepare): ?string
    {
        $args = $this->args($prepare);
        if ($args === []) {
            return null;
        }

        $query = $this->resolveKey($args[0]->value);
        if (!$query->isResolved()) {
            return null;
        }

        $values = [];
        foreach (array_slice($args, 1) as $arg) {
            $value = $this->resolveSqlValue($arg->value);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        $sql = preg_replace_callback('/%%|%[sdf]/', static function (array $m) use (&$values): string {
            if ($m[0] === '%%') {
                return '%';
            }

            $value = array_shift($values);
            if ($value === null) {
                return $m[0];
            }

            return $m[0] === '%s' ? "'" . str_replace("'", "\\'", $value) . "'" : $value;
        }, (string) $query->value);

        return $values === [] ? $sql : null;
    }

    /** A prepare() argument, reading `$wpdb->esc_like($x)` as $x with LIKE wildcards escaped. */
    private function resolveSqlValue(Expr $expr): ?string
    {
        if (Wpdb::isMethodCall($expr, 'esc_like') && $expr instanceof MethodCall) {
            $inner = $this->args($expr)[0]->value ?? null;
            $value = $inner !== null ? $this->resolveKey($inner) : null;

            return $value !== null && $value->isResolved() ? addcslashes((string) $value->value, '_%\\') : null;
        }

        if ($expr instanceof Concat) {
            $left = $this->resolveSqlValue($expr->left);
            $right = $this->resolveSqlValue($expr->right);

            return $left !== null && $right !== null ? $left . $right : null;
        }

        $value = $this->resolveKey($expr);

        return $value->isResolved() ? (string) $value->value : null;
    }

    /**
     * `$wp_filesystem->delete($path)` — the WP_Filesystem abstraction's removal,
     * mirroring the put_contents write the drop-in detector reads.
     */
    private function recordFileRemoval(MethodCall $node): void
    {
        if (
            !$node->name instanceof \PhpParser\Node\Identifier
            || strtolower((string) $node->name) !== 'delete'
            || !$node->var instanceof Variable
            || !is_string($node->var->name)
            || strtolower($node->var->name) !== 'wp_filesystem'
        ) {
            return;
        }

        $this->recordFileRemovalNode($node, '$wp_filesystem->delete', $this->argValue($this->args($node), 0, 'path'));
    }

    /**
     * Classify a deleted path as a drop-in or mu-plugin removal, reusing the
     * detector's rules so a create and its cleanup always agree on the key.
     */
    private function recordFileRemovalNode(FuncCall|MethodCall $node, string $via, ?Expr $target): void
    {
        if ($target === null) {
            return;
        }

        $rooted = WpRoots::split($target);
        if ($rooted === null) {
            return; // an un-rooted path is not provably one of ours
        }

        [$token, $remainder] = $rooted;
        if ($remainder === null) {
            return;
        }

        $tail = $this->resolveKey($remainder);
        if (!$tail->isResolved() || $tail->value === '') {
            return;
        }

        $path = $token . $tail->value;

        // The classification lives with the detector so the two ends of the
        // diff can never disagree about what a path means.
        $type = DropinVisitor::classifyPath($path);
        if ($type !== null) {
            $this->addRemoval($type, $path, $via);
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
            'function' => $this->scope(), // the function it sits in
            'file' => $this->file,
        ];
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
    public function deactivationCallbacks(): array
    {
        return $this->deactivationCallbacks;
    }

    /** @return list<array{from: string|null, file: string, to: string}> */
    public function calls(): array
    {
        return array_values($this->calls);
    }

    /**
     * require/include edges out of this file. WordPress runs only the plugin-root
     * uninstall.php, but a real teardown is often split across files pulled in
     * with require — and top-level code in those files runs on uninstall too.
     *
     * A target anchored to the requiring file's directory resolves to one
     * project-relative path (`__DIR__ . '/x.php'`, `plugin_dir_path(__FILE__) .
     * 'x.php'`). A bare string ('tasks.php') PHP would hunt for in the caller's
     * directory as well, so both readings are recorded — whichever misses simply
     * never matches a scanned file, so an over-broad candidate cannot credit a
     * removal that does not exist.
     */
    private function recordRequire(Include_ $node): void
    {
        $candidates = $this->requireCandidates($node->expr);

        if (count($candidates) === 1 && $node->expr instanceof String_) {
            $candidates[] = $this->normalizePath($this->dirPrefix() . $candidates[0]);
        }

        foreach ($candidates as $to) {
            if ($to !== '') {
                $this->requires[] = ['from' => $this->file, 'to' => $to];
            }
        }
    }

    /**
     * Every path the require expression can denote, resolved against the
     * requiring file's directory. Unresolvable pieces (variables, function
     * results other than the two known helpers) yield no candidates — an edge
     * we cannot name is one we do not follow.
     *
     * @return list<string>
     */
    private function requireCandidates(Expr $expr): array
    {
        if ($expr instanceof String_) {
            return [$this->normalizePath($expr->value)];
        }

        if ($expr instanceof MagicDir || $expr instanceof MagicFile) {
            return [$this->dirPrefix()];
        }

        // The canonical WordPress form: plugin_dir_path(__FILE__) . 'x.php'.
        if (
            $expr instanceof FuncCall
            && $expr->name instanceof Name
            && strtolower($expr->name->toString()) === 'plugin_dir_path'
            && ($first = $this->positionalArg($this->args($expr), 0)) !== null
        ) {
            return $this->requireCandidates($first->value);
        }

        if ($expr instanceof Concat) {
            $candidates = [];
            foreach ($this->requireCandidates($expr->left) as $left) {
                foreach ($this->requireCandidates($expr->right) as $right) {
                    $candidates[] = $this->normalizePath($left . $right);
                }
            }

            return $candidates;
        }

        return [];
    }

    /** The requiring file's directory as a root-relative prefix, with trailing slash. */
    private function dirPrefix(): string
    {
        $dir = str_replace('\\', '/', dirname($this->file));

        return $dir === '.' ? '' : $dir . '/';
    }

    /** Forward slashes, no empty/./ segments, .. collapsed against what precedes it. */
    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' && $segments !== []) {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    public function requires(): array
    {
        return $this->requires;
    }

    /** @return list<array{type: string, function: string|null, file: string}> */
    public function blanketRemovals(): array
    {
        return $this->blankets;
    }

    /** @return list<array{option: string|null, default: bool|string|null, function: string|null, file: string, exits: bool, removes: bool, calls: list<string>}> */
    public function guards(): array
    {
        return $this->guards;
    }
}
