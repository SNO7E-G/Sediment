<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Detects cron writes (M5, §7): wp_schedule_event and wp_schedule_single_event —
 * capturing the hook as the finding's key and the recurrence separately.
 *
 *  - wp_schedule_event($timestamp, $recurrence, $hook, $args = [])  — hook is
 *    arg 2, recurrence is arg 1 and is itself resolved (it is frequently a
 *    literal even when the hook is not)
 *  - wp_schedule_single_event($timestamp, $hook, $args = [])        — hook is
 *    arg 1; recurrence is always the literal 'single' (it fires once, never
 *    recurs, so there is nothing to resolve)
 *
 * The `cron_schedules` filter (custom recurrence intervals) is out of scope
 * here: it registers an interval definition, not a scheduled event, so it
 * writes nothing to the `cron` option by itself.
 */
final class CronVisitor extends AbstractDetectionVisitor
{
    private const RECURRENCE_SINGLE = 'single';

    protected function inspect(Node $node): void
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name) {
            return;
        }

        $fn = strtolower($node->name->toString());

        if ($fn !== 'wp_schedule_event' && $fn !== 'wp_reschedule_event' && $fn !== 'wp_schedule_single_event') {
            return;
        }

        // `wp_schedule_event(...)` as a callable still schedules something —
        // recorded as dynamic so coverage sees it.
        if ($this->recordFirstClassCallable($node, 'cron', $fn)) {
            return;
        }

        $args = $node->getArgs();

        // wp_reschedule_event shares wp_schedule_event's signature: timestamp,
        // recurrence, hook, args. It is rare, but it writes the cron option
        // exactly the same way.
        if ($fn === 'wp_schedule_event' || $fn === 'wp_reschedule_event') {
            $this->recordScheduleEvent($node, $fn, $args);

            return;
        }

        if ($fn === 'wp_schedule_single_event') {
            $this->recordSingleEvent($node, $fn, $args);
        }
    }

    /**
     * @param list<Arg> $args
     */
    private function recordScheduleEvent(Node $node, string $fn, array $args): void
    {
        $hookValue = $this->argValue($args, 2, 'hook');
        $resolution = $hookValue !== null ? $this->resolveFindingKey($hookValue, $node) : Resolution::dynamic();

        $recurrence = null;
        $recurrenceValue = $this->argValue($args, 1, 'recurrence');
        if ($recurrenceValue !== null) {
            $r = $this->resolveKey($recurrenceValue);
            $recurrence = $r->isResolved() ? $r->value : null;
        }

        $this->findings[] = new Finding(
            type: 'cron',
            function: $fn,
            key: $resolution->key(),
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $resolution->raw,
            recurrence: $recurrence,
            hasArgs: $this->passesArgs($args, 3, 'args'),
        );
    }

    /**
     * @param list<Arg> $args
     */
    private function recordSingleEvent(Node $node, string $fn, array $args): void
    {
        $hookValue = $this->argValue($args, 1, 'hook');
        $resolution = $hookValue !== null ? $this->resolveFindingKey($hookValue, $node) : Resolution::dynamic();

        $this->findings[] = new Finding(
            type: 'cron',
            function: $fn,
            key: $resolution->key(),
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $resolution->raw,
            recurrence: self::RECURRENCE_SINGLE,
            hasArgs: $this->passesArgs($args, 2, 'args'),
        );
    }
}
