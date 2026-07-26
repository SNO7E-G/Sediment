<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\SymbolCollector;
use Sediment\Analyzer\SymbolTable;
use Sediment\Analyzer\Visitors\ScheduleVisitor;

/**
 * Drives the symbol table + resolver + schedule visitor together over inline
 * snippets. Pins hook/recurrence resolution (§8) for the four Action
 * Scheduler entry points handled by ScheduleVisitor.
 */
final class ScheduleVisitorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function actions(string $body): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse("<?php\n" . $body);
        self::assertNotNull($ast);

        $names = new NodeTraverser();
        $names->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false]));
        $ast = $names->traverse($ast);

        $symbols = new SymbolTable();
        $collect = new NodeTraverser();
        $collect->addVisitor(new SymbolCollector($symbols));
        $collect->traverse($ast);
        $symbols->reconcileInheritedProperties();

        $visitor = new ScheduleVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    private function firstAction(string $body): Finding
    {
        $findings = $this->actions($body);
        self::assertNotEmpty($findings, 'expected at least one action finding');

        return $findings[0];
    }

    /**
     * @return iterable<string, array{string, string, ?string, ?string}>
     */
    public static function keyCases(): iterable
    {
        yield 'recurring action, literal hook, int-literal interval never resolves' => [
            "as_schedule_recurring_action(time(), 3600, 'sf_sync');",
            Finding::CONFIDENCE_VERIFIED,
            'sf_sync',
            null,
        ];

        yield 'recurring action, hook and interval from define() constants' => [
            "define('SF_INTERVAL', '3600');\nas_schedule_recurring_action(time(), SF_INTERVAL, 'sf_cleanup');",
            Finding::CONFIDENCE_VERIFIED,
            'sf_cleanup',
            '3600',
        ];

        yield 'recurring action, hook from class const' => [
            "class C { const HOOK = 'sf_task'; function f() { as_schedule_recurring_action(time(), 3600, self::HOOK); } }",
            Finding::CONFIDENCE_RESOLVED,
            'sf_task',
            null,
        ];

        yield 'single action, literal hook, recurrence forced to single' => [
            "as_schedule_single_action(time() + 60, 'sf_one_off');",
            Finding::CONFIDENCE_VERIFIED,
            'sf_one_off',
            'single',
        ];

        yield 'single action, dynamic hook' => [
            "as_schedule_single_action(time(), \$hook);",
            Finding::CONFIDENCE_DYNAMIC,
            null,
            'single',
        ];

        yield 'cron action, literal hook and literal schedule' => [
            "as_schedule_cron_action(time(), '*/5 * * * *', 'sf_cron_task');",
            Finding::CONFIDENCE_VERIFIED,
            'sf_cron_task',
            '*/5 * * * *',
        ];

        yield 'cron action, dynamic schedule resolves recurrence to null' => [
            "as_schedule_cron_action(time(), \$schedule, 'sf_cron_task2');",
            Finding::CONFIDENCE_VERIFIED,
            'sf_cron_task2',
            null,
        ];

        yield 'async action, literal hook, recurrence forced to async' => [
            "as_enqueue_async_action('sf_async_task');",
            Finding::CONFIDENCE_VERIFIED,
            'sf_async_task',
            'async',
        ];
    }

    #[DataProvider('keyCases')]
    public function test_key_and_recurrence_resolution(string $body, string $confidence, ?string $key, ?string $recurrence): void
    {
        $finding = $this->firstAction($body);

        self::assertSame('action', $finding->type);
        self::assertSame($confidence, $finding->confidence);
        self::assertSame($key, $finding->key);
        self::assertSame($recurrence, $finding->recurrence);
    }

    public function test_dynamic_findings_keep_the_raw_expression(): void
    {
        $finding = $this->firstAction("as_enqueue_async_action(\$this->buildHook());");

        self::assertSame(Finding::CONFIDENCE_DYNAMIC, $finding->confidence);
        self::assertNotNull($finding->expression);
        self::assertStringContainsString('buildHook', (string) $finding->expression);
    }
}
