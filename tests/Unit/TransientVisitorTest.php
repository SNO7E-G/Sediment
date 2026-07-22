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
use Sediment\Analyzer\Visitors\TransientVisitor;

/**
 * Drives the symbol table + resolver + transient visitor together over inline
 * snippets. Pins key resolution (§8) for set_transient and set_site_transient.
 */
final class TransientVisitorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function transients(string $body): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse("<?php\n" . $body);
        self::assertNotNull($ast);

        $symbols = new SymbolTable();
        $collect = new NodeTraverser();
        $collect->addVisitor(new SymbolCollector($symbols));
        $collect->traverse($ast);

        $visitor = new TransientVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    private function firstTransient(string $body): Finding
    {
        $findings = $this->transients($body);
        self::assertNotEmpty($findings, 'expected at least one transient finding');

        return $findings[0];
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function keyCases(): iterable
    {
        yield 'literal string' => [
            "set_transient('tf_cache', 'value', 3600);",
            Finding::CONFIDENCE_VERIFIED,
            'tf_cache',
        ];

        yield 'literal site transient' => [
            "set_site_transient('tf_net_cache', 'value', 3600);",
            Finding::CONFIDENCE_VERIFIED,
            'tf_net_cache',
        ];

        yield 'key from define constant' => [
            "define('TF_PREFIX', 'tf_');\nset_transient(TF_PREFIX . 'sync', 'value', 3600);",
            Finding::CONFIDENCE_RESOLVED,
            'tf_sync',
        ];

        yield 'key from class const' => [
            "class T { const KEY = 'tf_store'; function f() { set_transient(self::KEY, 'value', 3600); } }",
            Finding::CONFIDENCE_RESOLVED,
            'tf_store',
        ];

        yield 'concat with dynamic tail is a pattern' => [
            "set_transient('tf_' . \$section, 'value', 3600);",
            Finding::CONFIDENCE_PATTERN,
            'tf_*',
        ];

        yield 'bare variable is dynamic' => [
            "set_transient(\$key, 'value', 3600);",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];
    }

    #[DataProvider('keyCases')]
    public function test_key_resolution(string $body, string $confidence, ?string $key): void
    {
        $finding = $this->firstTransient($body);

        self::assertSame('transient', $finding->type);
        self::assertSame($confidence, $finding->confidence);
        self::assertSame($key, $finding->key);
    }

    public function test_dynamic_findings_keep_the_raw_expression(): void
    {
        $finding = $this->firstTransient('set_transient($this->buildKey($section), $value, 3600);');

        self::assertSame(Finding::CONFIDENCE_DYNAMIC, $finding->confidence);
        self::assertNotNull($finding->expression);
        self::assertStringContainsString('buildKey', (string) $finding->expression);
    }

    public function test_scanner_detects_transient_findings_in_fixture(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/transient-plugin');

        $transients = array_values(array_filter(
            $result['findings'],
            static fn (Finding $f): bool => $f->type === 'transient',
        ));

        self::assertNotEmpty($transients, 'expected at least one transient finding from the fixture');

        $byKey = [];
        foreach ($transients as $finding) {
            if ($finding->key !== null) {
                $byKey[$finding->key] = $finding;
            }
        }

        self::assertArrayHasKey('tf_cache', $byKey);
        self::assertSame(Finding::CONFIDENCE_VERIFIED, $byKey['tf_cache']->confidence);

        self::assertArrayHasKey('tf_network_cache', $byKey);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $byKey['tf_network_cache']->confidence);
        self::assertSame('set_site_transient', $byKey['tf_network_cache']->function);

        self::assertArrayHasKey('tf_store_data', $byKey);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $byKey['tf_store_data']->confidence);

        $dynamic = array_filter($transients, static fn (Finding $f): bool => $f->confidence === Finding::CONFIDENCE_DYNAMIC);
        self::assertCount(1, $dynamic, 'the runtime-keyed set_transient must degrade to dynamic');
    }
}
