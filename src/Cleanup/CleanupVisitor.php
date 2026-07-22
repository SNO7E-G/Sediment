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
use Sediment\Analyzer\Sql\TableStatements;
use Sediment\Analyzer\Visitors\AbstractDetectionVisitor;

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
    ];

    /** @var list<array{type: string, key: string, function: string|null, file: string}> */
    private array $removals = [];

    /** @var list<string> */
    private array $callbacks = [];

    /** @var list<string> lowercased function names called at the top level of uninstall.php */
    private array $uninstallCalls = [];

    protected function inspect(Node $node): void
    {
        if ($node instanceof FuncCall && $node->name instanceof Name && !$node->isFirstClassCallable()) {
            $function = strtolower($node->name->toString());

            if ($this->currentFunction() === null && $this->inUninstallPhp()) {
                $this->uninstallCalls[] = $function;
            }

            if ($function === 'register_uninstall_hook') {
                $this->recordCallback($node);
            } elseif (isset(self::REMOVALS[$function])) {
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
        [$keyIndex, $type, $parameter] = self::REMOVALS[$function];

        $value = $this->argValue($node->getArgs(), $keyIndex, $parameter);
        if ($value === null) {
            return;
        }

        $resolution = $this->resolveKey($value);
        if (!$resolution->isResolved()) {
            return; // pattern/dynamic removals cannot credit a specific create
        }

        $this->addRemoval($type, (string) $resolution->value);
    }

    private function recordDropTable(MethodCall $node): void
    {
        if (
            !$node->var instanceof Variable
            || $node->var->name !== 'wpdb'
            || !$node->name instanceof Node\Identifier
            || strtolower($node->name->toString()) !== 'query'
            || $node->isFirstClassCallable()
        ) {
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

    private function addRemoval(string $type, string $key): void
    {
        $this->removals[] = [
            'type' => $type,
            'key' => $key,
            'function' => $this->currentFunction(),
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

    /** @return list<string> */
    public function uninstallCalls(): array
    {
        return $this->uninstallCalls;
    }
}
