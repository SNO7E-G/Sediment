<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Detects transient writes (M6, §7): set_transient and set_site_transient —
 * one finding per call, keyed by the transient's canonical name.
 *
 * WordPress stores each transient as two option rows, `_transient_{key}` and
 * `_transient_timeout_{key}` (site variants `_site_transient_{key}` /
 * `_site_transient_timeout_{key}`); deriving those twins is the uninstall
 * generator's job later, so here we record the transient by its canonical
 * name.
 */
final class TransientVisitor extends AbstractDetectionVisitor
{
    /** function => key arg index */
    private const FUNCTIONS = [
        'set_transient'      => 0,
        'set_site_transient' => 0,
    ];

    protected function inspect(Node $node): void
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name || $node->isFirstClassCallable()) {
            return;
        }

        $function = strtolower($node->name->toString());
        if (!isset(self::FUNCTIONS[$function])) {
            return;
        }

        $args = $node->getArgs();
        $keyValue = $this->argValue($args, self::FUNCTIONS[$function], 'transient');
        $resolution = $keyValue !== null ? $this->resolveFindingKey($keyValue, $node) : Resolution::dynamic();

        $this->findings[] = new Finding(
            type: 'transient',
            function: $function,
            key: $resolution->key(),
            confidence: $resolution->confidence,
            file: $this->file,
            line: $node->getStartLine(),
            expression: $resolution->raw,
        );
    }
}
