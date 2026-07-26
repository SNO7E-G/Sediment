<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Analyzer\SymbolCollector;
use Sediment\Analyzer\SymbolTable;
use Sediment\Analyzer\Visitors\CronVisitor;

/**
 * Two edges documented as limitations in 0.2.0 and closed in 0.3.0: cron events
 * scheduled with arguments, and the $wpdb handle held as a property.
 */
final class CronArgsAndWpdbTest extends TestCase
{
    /** @return array<string, Finding> keyed by "type:key" */
    private function scan(string $fixture): array
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/' . $fixture);

        $byKey = [];
        foreach ($result['findings'] as $finding) {
            if ($finding->key !== null) {
                $byKey[$finding->type . ':' . $finding->key] = $finding;
            }
        }

        return $byKey;
    }

    /** @return list<Finding> */
    private function cron(string $body): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php\n" . $body);
        self::assertNotNull($ast);

        $names = new NodeTraverser();
        $names->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false]));
        $ast = $names->traverse($ast);

        $symbols = new SymbolTable();
        $collect = new NodeTraverser();
        $collect->addVisitor(new SymbolCollector($symbols));
        $collect->traverse($ast);
        $symbols->reconcileInheritedProperties();

        $visitor = new CronVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    public function test_it_records_whether_an_event_was_scheduled_with_arguments(): void
    {
        self::assertFalse($this->cron("wp_schedule_event(time(), 'daily', 'h');")[0]->hasArgs);
        self::assertFalse($this->cron("wp_schedule_event(time(), 'daily', 'h', array());")[0]->hasArgs);
        self::assertTrue($this->cron("wp_schedule_event(time(), 'daily', 'h', array(42));")[0]->hasArgs);
        self::assertTrue($this->cron("wp_schedule_single_event(time(), 'h', array(1));")[0]->hasArgs);
    }

    public function test_an_argless_clear_does_not_clean_an_event_scheduled_with_arguments(): void
    {
        $findings = $this->scan('cron-args-plugin');

        self::assertTrue($findings['cron:cap_plain']->cleaned, 'an argument-less event is cleared normally');
        self::assertFalse(
            $findings['cron:cap_with_args']->cleaned,
            'wp_clear_scheduled_hook only removes argument-less events, so this one survives',
        );
    }

    public function test_wp_unschedule_hook_clears_an_event_scheduled_with_arguments(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/unschedule-hook-plugin');

        $cron = array_values(array_filter(
            $result['findings'],
            static fn (Finding $f): bool => $f->type === 'cron' && $f->key === 'uhp_with_args',
        ));

        self::assertCount(1, $cron);
        self::assertTrue($cron[0]->cleaned, 'wp_unschedule_hook removes every event for the hook');
    }

    public function test_the_wpdb_handle_is_recognised_as_a_property(): void
    {
        $findings = $this->scan('cron-args-plugin');

        // $this->wpdb->query("CREATE TABLE {$this->wpdb->prefix}cap_logs ...")
        self::assertArrayHasKey('table:{prefix}cap_logs', $findings);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $findings['table:{prefix}cap_logs']->confidence);
    }
}
