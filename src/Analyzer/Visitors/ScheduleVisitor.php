<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;

/**
 * Detects Action Scheduler jobs (M5-adjacent): as_schedule_recurring_action,
 * as_schedule_single_action, as_schedule_cron_action, and
 * as_enqueue_async_action — a very common WordPress library for background
 * jobs, separate from WP-Cron (handled by {@see CronVisitor}).
 *
 *  - as_schedule_recurring_action($timestamp, $interval, $hook, ...) — hook is
 *    arg 2; the interval (arg 1) is resolved and, if it resolves, recorded as
 *    the recurrence. In practice the interval is usually a plain integer
 *    literal (e.g. `3600`), which ExpressionResolver has no branch for, so it
 *    stays unresolved unless the plugin routes it through a string constant.
 *  - as_schedule_single_action($timestamp, $hook, ...) — hook is arg 1;
 *    recurrence is always the literal 'single' (fires once, nothing to
 *    resolve).
 *  - as_schedule_cron_action($timestamp, $schedule, $hook, ...) — hook is
 *    arg 2; recurrence is the resolved schedule expression (arg 1) if it
 *    resolves, else null.
 *  - as_enqueue_async_action($hook, ...) — hook is arg 0; recurrence is
 *    always the literal 'async' (runs as soon as possible, once).
 */
final class ScheduleVisitor extends AbstractDetectionVisitor
{
    private const RECURRENCE_SINGLE = 'single';
    private const RECURRENCE_ASYNC = 'async';

    protected function inspect(Node $node): void
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name || $node->isFirstClassCallable()) {
            return;
        }

        $fn = strtolower($node->name->toString());
        $args = $node->getArgs();

        if ($fn === 'as_schedule_recurring_action') {
            $this->record($node, $fn, $args, 2, 'hook', $this->resolvedArg($args, 1, 'interval_in_seconds'));

            return;
        }

        if ($fn === 'as_schedule_single_action') {
            $this->record($node, $fn, $args, 1, 'hook', self::RECURRENCE_SINGLE);

            return;
        }

        if ($fn === 'as_schedule_cron_action') {
            $this->record($node, $fn, $args, 2, 'hook', $this->resolvedArg($args, 1, 'schedule'));

            return;
        }

        if ($fn === 'as_enqueue_async_action') {
            $this->record($node, $fn, $args, 0, 'hook', self::RECURRENCE_ASYNC);
        }
    }

    /**
     * @param list<Arg> $args
     */
    private function record(Node $node, string $fn, array $args, int $hookIndex, string $hookParam, ?string $recurrence): void
    {
        $hookValue = $this->argValue($args, $hookIndex, $hookParam);
        if ($hookValue === null) {
            return;
        }

        $resolution = $this->resolveKey($hookValue);

        $this->findings[] = new Finding(
            type: 'action',
            function: $fn,
            key: $resolution->key(),
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $resolution->raw,
            recurrence: $recurrence,
        );
    }

    /**
     * @param list<Arg> $args
     */
    private function resolvedArg(array $args, int $index, string $param): ?string
    {
        $value = $this->argValue($args, $index, $param);
        if ($value === null) {
            return null;
        }

        $resolution = $this->resolveKey($value);

        return $resolution->isResolved() ? $resolution->value : null;
    }
}
