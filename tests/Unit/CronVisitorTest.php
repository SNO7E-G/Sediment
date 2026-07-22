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
use Sediment\Analyzer\Visitors\CronVisitor;

/**
 * Drives the symbol table + resolver + cron visitor together over inline
 * snippets. Pins hook/recurrence resolution (§8) for wp_schedule_event and
 * wp_schedule_single_event.
 */
final class CronVisitorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function events(string $body): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse("<?php\n" . $body);
        self::assertNotNull($ast);

        $symbols = new SymbolTable();
        $collect = new NodeTraverser();
        $collect->addVisitor(new SymbolCollector($symbols));
        $collect->traverse($ast);

        $visitor = new CronVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    private function firstEvent(string $body): Finding
    {
        $findings = $this->events($body);
        self::assertNotEmpty($findings, 'expected at least one cron finding');

        return $findings[0];
    }

    /**
     * @return iterable<string, array{string, string, ?string, ?string}>
     */
    public static function keyCases(): iterable
    {
        yield 'literal hook and recurrence' => [
            "wp_schedule_event(time(), 'daily', 'cf_cleanup');",
            Finding::CONFIDENCE_VERIFIED,
            'cf_cleanup',
            'daily',
        ];

        yield 'hook from define constant' => [
            "define('CF_PREFIX', 'cf_');\nwp_schedule_event(time(), 'hourly', CF_PREFIX . 'sync');",
            Finding::CONFIDENCE_RESOLVED,
            'cf_sync',
            'hourly',
        ];

        yield 'hook from class const' => [
            "class C { const HOOK = 'cf_task'; function f() { wp_schedule_event(time(), 'daily', self::HOOK); } }",
            Finding::CONFIDENCE_RESOLVED,
            'cf_task',
            'daily',
        ];

        yield 'dynamic hook keeps literal recurrence' => [
            "wp_schedule_event(time(), 'twicedaily', \$hook);",
            Finding::CONFIDENCE_DYNAMIC,
            null,
            'twicedaily',
        ];

        yield 'dynamic recurrence resolves to null' => [
            "wp_schedule_event(time(), \$freq, 'cf_task');",
            Finding::CONFIDENCE_VERIFIED,
            'cf_task',
            null,
        ];

        yield 'single event hook is verified, recurrence forced to single' => [
            "wp_schedule_single_event(time() + 3600, 'cf_one_off');",
            Finding::CONFIDENCE_VERIFIED,
            'cf_one_off',
            'single',
        ];

        yield 'single event with dynamic hook' => [
            "wp_schedule_single_event(time(), \$hook);",
            Finding::CONFIDENCE_DYNAMIC,
            null,
            'single',
        ];
    }

    #[DataProvider('keyCases')]
    public function test_key_and_recurrence_resolution(string $body, string $confidence, ?string $key, ?string $recurrence): void
    {
        $finding = $this->firstEvent($body);

        self::assertSame('cron', $finding->type);
        self::assertSame($confidence, $finding->confidence);
        self::assertSame($key, $finding->key);
        self::assertSame($recurrence, $finding->recurrence);
    }

    public function test_dynamic_findings_keep_the_raw_expression(): void
    {
        $finding = $this->firstEvent("wp_schedule_event(time(), 'daily', \$this->buildHook());");

        self::assertSame(Finding::CONFIDENCE_DYNAMIC, $finding->confidence);
        self::assertNotNull($finding->expression);
        self::assertStringContainsString('buildHook', (string) $finding->expression);
    }

    public function test_scanner_detects_cron_findings_in_fixture(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/cron-plugin');

        $cron = array_values(array_filter(
            $result['findings'],
            static fn (Finding $f): bool => $f->type === 'cron',
        ));

        self::assertNotEmpty($cron, 'expected at least one cron finding from the fixture');

        $byKey = [];
        foreach ($cron as $finding) {
            if ($finding->key !== null) {
                $byKey[$finding->key] = $finding;
            }
        }

        self::assertArrayHasKey('cronf_cleanup', $byKey);
        self::assertSame(Finding::CONFIDENCE_VERIFIED, $byKey['cronf_cleanup']->confidence);
        self::assertSame('daily', $byKey['cronf_cleanup']->recurrence);

        self::assertArrayHasKey('cronf_sync', $byKey);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $byKey['cronf_sync']->confidence);
        self::assertSame('hourly', $byKey['cronf_sync']->recurrence);

        self::assertArrayHasKey('cronf_one_off_task', $byKey);
        self::assertSame('single', $byKey['cronf_one_off_task']->recurrence);

        $dynamic = array_filter($cron, static fn (Finding $f): bool => $f->confidence === Finding::CONFIDENCE_DYNAMIC);
        self::assertCount(1, $dynamic, 'the runtime-keyed wp_schedule_event must degrade to dynamic');
    }
}
