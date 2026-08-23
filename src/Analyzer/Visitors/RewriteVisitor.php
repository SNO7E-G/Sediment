<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;

/**
 * Detects rewrite rules a plugin adds to the routing table.
 *
 * Rules live in the `rewrite_rules` option, which WordPress rebuilds when
 * permalinks are flushed. A plugin that adds rules and never flushes on
 * uninstall leaves them in that option until something else triggers a rebuild,
 * where they can route requests to a handler that no longer exists.
 *
 * They are recorded as findings but weigh lightly: unlike a table or an
 * autoloaded option, a rule is one entry in a single option and disappears on
 * the next flush.
 */
final class RewriteVisitor extends AbstractDetectionVisitor
{
    /** function => [key arg index, key parameter name] */
    private const FUNCTIONS = [
        'add_rewrite_rule'     => [0, 'regex'],
        'add_rewrite_endpoint' => [0, 'name'],
        'add_rewrite_tag'      => [0, 'tag'],
    ];

    protected function inspect(Node $node): void
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name) {
            return;
        }

        $function = strtolower($node->name->toString());
        if (!isset(self::FUNCTIONS[$function])) {
            return;
        }

        if ($this->recordFirstClassCallable($node, 'rewrite_rule', $function)) {
            return;
        }

        [$index, $parameter] = self::FUNCTIONS[$function];

        $value = $this->argValue($node->getArgs(), $index, $parameter);
        if ($value === null) {
            return;
        }

        $resolution = $this->resolveFindingKey($value, $node);

        $this->findings[] = new Finding(
            type: 'rewrite_rule',
            function: $function,
            key: $resolution->key(),
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $resolution->raw,
        );
    }
}
