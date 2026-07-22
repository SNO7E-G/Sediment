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
use PhpParser\Node\Scalar\MagicConst\Class_ as MagicClass;
use PhpParser\Node\Scalar\String_;
use Sediment\Analyzer\Visitors\AbstractDetectionVisitor;

/**
 * Finds the cleanup path (M7): the removal calls a plugin makes to undo what it
 * created, and the `register_uninstall_hook` callbacks that run them.
 *
 * Parsed with the same engine and resolver as detection, so a
 * `delete_option($this->prefix . 'x')` is matched against the create it mirrors.
 * It records the enclosing function of each removal and the registered
 * uninstall callbacks; the {@see CleanupDiffer} decides which removals actually
 * run on uninstall.
 */
final class CleanupVisitor extends AbstractDetectionVisitor
{
    /** removal function => [key arg index, artifact type] */
    private const REMOVALS = [
        'delete_option'          => [0, 'option'],
        'delete_site_option'     => [0, 'option'],
        'delete_transient'       => [0, 'transient'],
        'delete_site_transient'  => [0, 'transient'],
        'wp_clear_scheduled_hook' => [0, 'cron'],
        'wp_unschedule_hook'     => [0, 'cron'],
        'wp_unschedule_event'    => [1, 'cron'],
    ];

    private const DROP_TABLE = '/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([^\s`(;]+)`?/i';

    /** @var list<array{type: string, key: string, function: string|null, file: string}> */
    private array $removals = [];

    /** @var list<string> */
    private array $callbacks = [];

    protected function inspect(Node $node): void
    {
        if ($node instanceof FuncCall && $node->name instanceof Name && !$node->isFirstClassCallable()) {
            $function = strtolower($node->name->toString());

            if ($function === 'register_uninstall_hook') {
                $this->recordCallback($node);

                return;
            }

            if (isset(self::REMOVALS[$function])) {
                $this->recordRemoval($node, $function);
            }

            return;
        }

        if ($node instanceof MethodCall) {
            $this->recordDropTable($node);
        }
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
        [$keyIndex, $type] = self::REMOVALS[$function];

        $value = $this->argValue($node->getArgs(), $keyIndex, 'option');
        if ($value === null) {
            return;
        }

        $resolution = $this->resolveKey($value);
        $key = $resolution->key();
        if ($key === null) {
            return; // an unresolvable removal cannot credit any specific create
        }

        $this->removals[] = [
            'type' => $type,
            'key' => $key,
            'function' => $this->currentFunction(),
            'file' => $this->file,
        ];
    }

    private function recordDropTable(MethodCall $node): void
    {
        if (
            !$node->var instanceof Variable
            || $node->var->name !== 'wpdb'
            || $node->isFirstClassCallable()
        ) {
            return;
        }

        $value = $this->argValue($node->getArgs(), 0, 'query');
        if ($value === null) {
            return;
        }

        $resolution = $this->resolveKey($value);
        if ($resolution->value === null || preg_match(self::DROP_TABLE, $resolution->value, $matches) !== 1) {
            return;
        }

        $name = trim($matches[1], '`');
        if ($name === '') {
            return;
        }

        $this->removals[] = [
            'type' => 'table',
            'key' => $name,
            'function' => $this->currentFunction(),
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
        if ($expr instanceof MagicClass) {
            return $this->currentClass();
        }

        if ($expr instanceof Variable && $expr->name === 'this') {
            return $this->currentClass();
        }

        if ($expr instanceof String_) {
            return $expr->value !== '' ? $expr->value : null;
        }

        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name) {
            $reference = strtolower($expr->class->toString());

            return $reference === 'self' || $reference === 'static'
                ? $this->currentClass()
                : $expr->class->getLast();
        }

        return null;
    }

    /**
     * @return list<array{type: string, key: string, function: string|null, file: string}>
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
}
