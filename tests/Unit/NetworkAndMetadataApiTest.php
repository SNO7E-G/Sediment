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
use Sediment\Analyzer\Scanner;
use Sediment\Analyzer\SymbolCollector;
use Sediment\Analyzer\SymbolTable;
use Sediment\Analyzer\Visitors\MetaVisitor;
use Sediment\Analyzer\Visitors\OptionVisitor;
use Sediment\Generator\UninstallGenerator;

/**
 * The multisite network option API and the generic metadata API. Both take the
 * key at a different argument position than their better-known twins, which is
 * exactly why they were invisible.
 */
final class NetworkAndMetadataApiTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function findings(string $body, string $visitorClass): array
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

        $visitor = new $visitorClass('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    /**
     * @return iterable<string, array{string, string, ?string, ?string}>
     */
    public static function networkOptionCases(): iterable
    {
        // The network id comes first, so the key is argument 1.
        yield 'add_network_option' => ["add_network_option(1, 'nw_settings', array());", 'nw_settings', Finding::CONFIDENCE_VERIFIED, null];
        yield 'update_network_option' => ["update_network_option(null, 'nw_flag', 1);", 'nw_flag', Finding::CONFIDENCE_VERIFIED, null];
        yield 'named arguments still resolve' => ["add_network_option(network_id: 1, option: 'nw_named', value: 2);", 'nw_named', Finding::CONFIDENCE_VERIFIED, null];
        yield 'a dynamic network key degrades' => ["update_network_option(1, \$key, 1);", null, Finding::CONFIDENCE_DYNAMIC, null];
    }

    #[DataProvider('networkOptionCases')]
    public function test_network_options_are_detected(string $body, ?string $key, string $confidence, ?string $autoload): void
    {
        $findings = $this->findings($body, OptionVisitor::class);

        self::assertCount(1, $findings);
        self::assertSame('option', $findings[0]->type);
        self::assertSame($key, $findings[0]->key);
        self::assertSame($confidence, $findings[0]->confidence);
        self::assertSame($autoload, $findings[0]->autoload, 'network options are never autoloaded');
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function metadataCases(): iterable
    {
        yield 'add_metadata post' => ["add_metadata('post', 1, 'gm_ref', 'v');", 'post_meta', 'gm_ref'];
        yield 'update_metadata user' => ["update_metadata('user', 1, 'gm_pref', 'v');", 'user_meta', 'gm_pref'];
        yield 'update_metadata term' => ["update_metadata('term', 1, 'gm_t', 'v');", 'term_meta', 'gm_t'];
        yield 'update_metadata comment' => ["update_metadata('comment', 1, 'gm_c', 'v');", 'comment_meta', 'gm_c'];
    }

    #[DataProvider('metadataCases')]
    public function test_the_generic_metadata_api_is_detected(string $body, string $type, string $key): void
    {
        $findings = $this->findings($body, MetaVisitor::class);

        self::assertCount(1, $findings);
        self::assertSame($type, $findings[0]->type);
        self::assertSame($key, $findings[0]->key);
    }

    public function test_an_unknowable_metadata_object_type_emits_nothing(): void
    {
        // Guessing which meta table is touched would be worse than missing it.
        self::assertSame([], $this->findings("update_metadata(\$type, 1, 'gm_x', 'v');", MetaVisitor::class));
        self::assertSame([], $this->findings("update_metadata('widget', 1, 'gm_x', 'v');", MetaVisitor::class));
    }

    public function test_a_network_option_is_removed_the_site_way_in_a_generated_uninstall(): void
    {
        $finding = new Finding(
            type: 'option',
            function: 'add_network_option',
            key: 'nw_settings',
            confidence: Finding::CONFIDENCE_VERIFIED,
            file: 'plugin.php',
            line: 1,
            cleaned: false,
        );

        $code = (new UninstallGenerator())->generate([$finding], 'Acme');

        self::assertStringContainsString("delete_site_option('nw_settings');", $code);
        self::assertStringNotContainsString("delete_option('nw_settings');", $code);
    }

    public function test_network_and_metadata_cleanup_is_credited(): void
    {
        $scan = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/network-meta-plugin');

        $byKey = [];
        foreach ($scan['findings'] as $finding) {
            if ($finding->key !== null) {
                $byKey[$finding->type . ':' . $finding->key] = $finding;
            }
        }

        self::assertArrayHasKey('option:nmp_network', $byKey);
        self::assertTrue($byKey['option:nmp_network']->cleaned, 'delete_network_option should credit');
        self::assertArrayHasKey('post_meta:nmp_ref', $byKey);
        self::assertTrue($byKey['post_meta:nmp_ref']->cleaned, 'delete_metadata should credit add_metadata');
    }
}
