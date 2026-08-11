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

    public function test_an_argless_unschedule_does_not_clean_an_action_scheduled_with_arguments(): void
    {
        // Action Scheduler matches pending actions by hook AND arguments, the
        // same way wp_clear_scheduled_hook does — crediting an args-blind
        // as_unschedule_action() would report a job as cleaned while it keeps
        // firing after uninstall.
        $findings = $this->scanInline(
            "<?php\n/* Plugin Name: AS Args */\n"
            . "as_schedule_recurring_action(time(), 3600, 'asa_with_args', ['id' => 5]);\n"
            . "as_schedule_single_action(time(), 'asa_plain');\n",
            "<?php\nas_unschedule_action('asa_with_args');\nas_unschedule_action('asa_plain');\n",
        );

        self::assertTrue($findings['action:asa_plain']->cleaned);
        self::assertFalse($findings['action:asa_with_args']->cleaned);
    }

    public function test_as_unschedule_all_actions_clears_an_action_scheduled_with_arguments(): void
    {
        $findings = $this->scanInline(
            "<?php\n/* Plugin Name: AS Args */\n"
            . "as_schedule_recurring_action(time(), 3600, 'asa_with_args', ['id' => 5]);\n",
            "<?php\nas_unschedule_all_actions('asa_with_args');\n",
        );

        self::assertTrue($findings['action:asa_with_args']->cleaned, 'the blanket clear removes every pending action for the hook');
    }

    /** @return array<string, Finding> keyed by "type:key" */
    private function scanInline(string $pluginPhp, string $uninstallPhp): array
    {
        $dir = sys_get_temp_dir() . '/sediment-as-args-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/plugin.php', $pluginPhp);
        file_put_contents($dir . '/uninstall.php', $uninstallPhp);

        try {
            $result = (new Scanner())->scan($dir);

            $byKey = [];
            foreach ($result['findings'] as $finding) {
                if ($finding->key !== null) {
                    $byKey[$finding->type . ':' . $finding->key] = $finding;
                }
            }

            return $byKey;
        } finally {
            @unlink($dir . '/plugin.php');
            @unlink($dir . '/uninstall.php');
            @rmdir($dir);
        }
    }

    public function test_the_wpdb_handle_is_recognised_as_a_property(): void
    {
        $findings = $this->scan('cron-args-plugin');

        // $this->wpdb->query("CREATE TABLE {$this->wpdb->prefix}cap_logs ...")
        self::assertArrayHasKey('table:{prefix}cap_logs', $findings);
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $findings['table:{prefix}cap_logs']->confidence);
    }
}
