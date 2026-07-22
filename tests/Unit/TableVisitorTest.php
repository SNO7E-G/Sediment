<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Analyzer\SymbolCollector;
use Sediment\Analyzer\SymbolTable;
use Sediment\Analyzer\Visitors\TableVisitor;

/**
 * Pins table detection (M4): dbDelta() and $wpdb->query() CREATE TABLE, with the
 * SQL resolved first (including $wpdb->prefix -> {prefix} and local-variable SQL)
 * and the table name pulled from the resolved string.
 */
final class TableVisitorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function tables(string $body): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse("<?php\n" . $body);
        self::assertNotNull($ast);

        $symbols = new SymbolTable();
        $collect = new NodeTraverser();
        $collect->addVisitor(new SymbolCollector($symbols));
        $collect->traverse($ast);
        $symbols->reconcileInheritedProperties();

        $visitor = new TableVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    private function firstTable(string $body): Finding
    {
        $findings = $this->tables($body);
        self::assertNotEmpty($findings, 'expected at least one table finding');

        return $findings[0];
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function tableCases(): iterable
    {
        yield 'dbDelta with interpolated prefix in a local variable' => [
            "function f() { global \$wpdb; \$sql = \"CREATE TABLE {\$wpdb->prefix}tp_logs (id INT)\"; dbDelta(\$sql); }",
            Finding::CONFIDENCE_RESOLVED,
            '{prefix}tp_logs',
        ];

        yield 'dbDelta with interpolated prefix inline' => [
            "function f() { global \$wpdb; dbDelta(\"CREATE TABLE {\$wpdb->prefix}tp_logs (id INT)\"); }",
            Finding::CONFIDENCE_RESOLVED,
            '{prefix}tp_logs',
        ];

        yield 'dbDelta with concatenated prefix' => [
            "function f() { global \$wpdb; dbDelta('CREATE TABLE ' . \$wpdb->prefix . 'tp_cache (id INT)'); }",
            Finding::CONFIDENCE_RESOLVED,
            '{prefix}tp_cache',
        ];

        yield 'dbDelta with a hardcoded literal name' => [
            "dbDelta('CREATE TABLE wp_custom (id INT)');",
            Finding::CONFIDENCE_VERIFIED,
            'wp_custom',
        ];

        yield 'dbDelta with IF NOT EXISTS and backticks' => [
            "function f() { global \$wpdb; dbDelta(\"CREATE TABLE IF NOT EXISTS `{\$wpdb->prefix}tp_meta` (id INT)\"); }",
            Finding::CONFIDENCE_RESOLVED,
            '{prefix}tp_meta',
        ];

        yield 'wpdb->query direct CREATE TABLE' => [
            "function f() { global \$wpdb; \$wpdb->query(\"CREATE TABLE {\$wpdb->prefix}tp_events (id INT)\"); }",
            Finding::CONFIDENCE_RESOLVED,
            '{prefix}tp_events',
        ];

        yield 'dbDelta with an unresolvable SQL variable is dynamic' => [
            "function f(\$name) { dbDelta(\$name); }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];
    }

    #[DataProvider('tableCases')]
    public function test_table_detection(string $body, string $confidence, ?string $name): void
    {
        $finding = $this->firstTable($body);

        self::assertSame('table', $finding->type);
        self::assertSame($confidence, $finding->confidence);
        self::assertSame($name, $finding->key);
    }

    public function test_wpdb_query_that_is_not_a_create_is_ignored(): void
    {
        $findings = $this->tables("function f() { global \$wpdb; \$wpdb->query('SELECT 1'); }");

        self::assertSame([], $findings, 'a non-CREATE query must not be reported as a table');
    }

    public function test_scanner_detects_tables_in_fixture(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/table-plugin');

        $byKey = [];
        $dynamic = 0;
        foreach ($result['findings'] as $finding) {
            if ($finding->type !== 'table') {
                continue;
            }
            if ($finding->key !== null) {
                $byKey[$finding->key] = $finding;
            } else {
                $dynamic++;
            }
        }

        self::assertArrayHasKey('{prefix}tp_logs', $byKey);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $byKey['{prefix}tp_logs']->confidence);
        self::assertArrayHasKey('{prefix}tp_cache', $byKey);
        self::assertSame(1, $dynamic, 'the dbDelta($name) call must degrade to a single dynamic table finding');
    }
}
