<?php

declare(strict_types=1);

namespace Sediment\Analyzer\Visitors;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Resolution;
use Sediment\Analyzer\Sql\TableStatements;
use Sediment\Analyzer\Wpdb;

/**
 * Detects table creation (M4, §7): dbDelta() and direct CREATE TABLE via
 * $wpdb->query().
 *
 * The method the spec insists on (§12): resolve the SQL *string* first — through
 * the same symbol/interpolation/local-variable resolver, so "{$wpdb->prefix}x"
 * becomes "{prefix}x" — then read the table name(s) from the resolved string
 * with {@see TableStatements}, which anchors to each statement so it never
 * mistakes "CREATE TABLE" inside an INSERT value for a real create. One finding
 * per CREATE statement; the {prefix} token is preserved for custom-prefix sites.
 */
final class TableVisitor extends AbstractDetectionVisitor
{
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

        $sql = $this->argValue($node->getArgs(), 0, 'queries');
        $resolution = $sql !== null ? $this->resolveKey($sql) : Resolution::dynamic();
        $names = $resolution->value !== null ? TableStatements::created($resolution->value) : [];

        if ($names === []) {
            // dbDelta is always a schema write, so record the unresolved case.
            $this->findings[] = $this->dynamicTable('dbDelta', $node->getStartLine(), $resolution->raw);

            return;
        }

        foreach ($names as $name) {
            $this->findings[] = $this->table('dbDelta', $name, $resolution->confidence, $node->getStartLine());
        }
    }

    private function inspectWpdbQuery(MethodCall $node): void
    {
        if (!Wpdb::isMethodCall($node, 'query')) {
            return;
        }

        $sql = $this->argValue($node->getArgs(), 0, 'query');
        if ($sql === null) {
            return;
        }

        $resolution = $this->resolveKey($sql);
        if ($resolution->value === null) {
            return; // a dynamic query — cannot tell whether it creates a table
        }

        foreach (TableStatements::created($resolution->value) as $name) {
            $this->findings[] = $this->table('$wpdb->query', $name, $resolution->confidence, $node->getStartLine());
        }
    }

    private function table(string $function, string $name, string $confidence, int $line): Finding
    {
        // The name is fully extracted, so a partly-dynamic (pattern) SQL body
        // does not make the name itself uncertain.
        return new Finding(
            type: 'table',
            function: $function,
            key: $name,
            confidence: $confidence === Finding::CONFIDENCE_PATTERN ? Finding::CONFIDENCE_RESOLVED : $confidence,
            file: $this->file,
            line: $line,
        );
    }

    private function dynamicTable(string $function, int $line, ?string $raw): Finding
    {
        return new Finding(
            type: 'table',
            function: $function,
            key: null,
            confidence: Finding::CONFIDENCE_DYNAMIC,
            file: $this->file,
            line: $line,
            expression: $raw,
        );
    }
}
