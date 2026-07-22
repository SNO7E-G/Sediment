<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;

/**
 * Detects table creation (M4, §7): dbDelta() and direct CREATE TABLE via
 * $wpdb->query().
 *
 * The method the spec insists on (§12): resolve the SQL *string* first — through
 * the same symbol/interpolation resolver as everything else, so
 * "{$wpdb->prefix}my_logs" becomes "{prefix}my_logs" — then run one lightweight
 * regex on the already-resolved string to pull the table name. Regex on a
 * resolved string is fine; regex on raw PHP is not.
 *
 * The table name keeps the {prefix} token (never a hardcoded wp_) so the finding
 * is correct on sites with a custom prefix.
 */
final class TableVisitor extends AbstractDetectionVisitor
{
    private const CREATE_TABLE = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^\s`(]+)`?/i';

    protected function inspect(Node $node): void
    {
        if ($node instanceof FuncCall) {
            $this->inspectDbDelta($node);

            return;
        }

        if ($node instanceof MethodCall) {
            $this->inspectWpdbQuery($node);
        }
    }

    private function inspectDbDelta(FuncCall $node): void
    {
        if (!$node->name instanceof Name || strtolower($node->name->toString()) !== 'dbdelta' || $node->isFirstClassCallable()) {
            return;
        }

        $sqlValue = $this->argValue($node->getArgs(), 0, 'queries');
        $resolution = $sqlValue !== null ? $this->resolveKey($sqlValue) : Resolution::dynamic();

        // dbDelta is always a schema write, so even an unresolvable argument is
        // worth recording as a dynamic table finding.
        $this->record('dbDelta', $resolution, $node->getStartLine());
    }

    private function inspectWpdbQuery(MethodCall $node): void
    {
        if (
            !$node->var instanceof Variable
            || $node->var->name !== 'wpdb'
            || !$node->name instanceof Identifier
            || strtolower($node->name->toString()) !== 'query'
            || $node->isFirstClassCallable()
        ) {
            return;
        }

        $sqlValue = $this->argValue($node->getArgs(), 0, 'query');
        if ($sqlValue === null) {
            return;
        }

        $resolution = $this->resolveKey($sqlValue);

        // $wpdb->query runs any SQL; only treat it as a table create when the
        // resolved statement actually is one. A dynamic query is not reported —
        // we cannot tell whether it creates a table.
        if ($resolution->value === null || !$this->looksLikeCreate($resolution->value)) {
            return;
        }

        $this->record('$wpdb->query', $resolution, $node->getStartLine());
    }

    private function record(string $function, Resolution $resolution, int $line): void
    {
        $name = $resolution->value !== null ? $this->extractTableName($resolution->value) : null;

        if ($name === null) {
            $this->findings[] = new Finding(
                type: 'table',
                function: $function,
                key: null,
                confidence: Finding::CONFIDENCE_DYNAMIC,
                file: $this->file,
                line: $line,
                expression: $resolution->raw,
            );

            return;
        }

        // The table NAME is fully known once extracted; a partly-dynamic SQL body
        // (pattern) does not make the name itself uncertain, so treat it as resolved.
        $confidence = $resolution->confidence === Finding::CONFIDENCE_PATTERN
            ? Finding::CONFIDENCE_RESOLVED
            : $resolution->confidence;

        $this->findings[] = new Finding(
            type: 'table',
            function: $function,
            key: $name,
            confidence: $confidence,
            file: $this->file,
            line: $line,
        );
    }

    private function looksLikeCreate(string $sql): bool
    {
        return (bool) preg_match('/CREATE\s+TABLE/i', $sql);
    }

    private function extractTableName(string $sql): ?string
    {
        if (preg_match(self::CREATE_TABLE, $sql, $matches) === 1) {
            $name = trim($matches[1], '`');

            return $name !== '' ? $name : null;
        }

        return null;
    }
}
