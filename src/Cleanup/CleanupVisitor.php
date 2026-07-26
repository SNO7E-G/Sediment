<?php

declare(strict_types=1);

namespace Sediment\Cleanup;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\MagicConst\Class_ as MagicClass;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\If_;
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
    ];

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

    /** @var list<array{option: string, default: bool|string|null, function: string|null, file: string}> */
    private array $guards = [];

    protected function inspect(Node $node): void
    {
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
     * Any `if` whose condition reads an option counts, regardless of how it is
     * compared or negated: what matters downstream is which option decides
     * whether cleanup runs, and what that option defaults to.
     */
    private function recordGuard(If_ $node): void
    {
        foreach ((new NodeFinder())->findInstanceOf($node->cond, FuncCall::class) as $call) {
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
            ];

            return; // one guard per `if` is enough to mark cleanup conditional
        }
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

        $resolution = $this->resolveKey($value);
        if (!$resolution->isResolved()) {
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

        $this->addRemoval(self::META_OBJECT_TYPES[(string) $type->value], (string) $key->value);
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

        foreach (TableStatements::dropped($resolution->value) as $name) {
            $this->addRemoval('table', $name);
        }
    }

    private function addRemoval(string $type, string $key, string $via = ''): void
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

    /** @return list<array{option: string, default: bool|string|null, function: string|null, file: string}> */
    public function guards(): array
    {
        return $this->guards;
    }
}
