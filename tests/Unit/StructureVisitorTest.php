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
use Sediment\Analyzer\Visitors\StructureVisitor;

/**
 * Drives the symbol table + resolver + structure visitor together over
 * inline snippets. Pins key resolution (§8) for add_role, register_post_type,
 * register_taxonomy, the add_role() capabilities array, and $role->add_cap().
 */
final class StructureVisitorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function structures(string $body): array
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

        $visitor = new StructureVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    private function firstOfType(string $body, string $type): Finding
    {
        foreach ($this->structures($body) as $finding) {
            if ($finding->type === $type) {
                return $finding;
            }
        }

        self::fail("expected at least one '{$type}' finding");
    }

    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function keyCases(): iterable
    {
        yield 'add_role literal' => [
            "add_role('sp_editor', 'SP Editor', []);",
            'role',
            Finding::CONFIDENCE_VERIFIED,
            'sp_editor',
        ];

        yield 'register_post_type literal' => [
            "register_post_type('sp_listing', []);",
            'post_type',
            Finding::CONFIDENCE_VERIFIED,
            'sp_listing',
        ];

        yield 'register_post_type from define constant' => [
            "define('SP_PREFIX', 'sp_');\nregister_post_type(SP_PREFIX . 'listing', []);",
            'post_type',
            Finding::CONFIDENCE_RESOLVED,
            'sp_listing',
        ];

        yield 'register_taxonomy literal' => [
            "register_taxonomy('sp_genre', 'post', []);",
            'taxonomy',
            Finding::CONFIDENCE_VERIFIED,
            'sp_genre',
        ];

        yield 'register_taxonomy from class const' => [
            "class T { const NAME = 'sp_genre'; function f() { register_taxonomy(self::NAME, 'post', []); } }",
            'taxonomy',
            Finding::CONFIDENCE_RESOLVED,
            'sp_genre',
        ];

        yield 'register_post_type dynamic' => [
            "register_post_type(\$type, []);",
            'post_type',
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'register_taxonomy dynamic' => [
            "register_taxonomy(\$taxonomy, 'post', []);",
            'taxonomy',
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];
    }

    #[DataProvider('keyCases')]
    public function test_key_resolution(string $body, string $type, string $confidence, ?string $key): void
    {
        $finding = $this->firstOfType($body, $type);

        self::assertSame($confidence, $finding->confidence);
        self::assertSame($key, $finding->key);
    }

    public function test_add_role_emits_one_capability_per_literal_key(): void
    {
        $findings = $this->structures(
            "add_role('sp_editor', 'SP Editor', ['sp_manage_widgets' => true, 'read' => true]);"
        );

        $capabilities = array_values(array_filter(
            $findings,
            static fn (Finding $f): bool => $f->type === 'capability',
        ));

        self::assertCount(2, $capabilities);

        $keys = array_map(static fn (Finding $f): ?string => $f->key, $capabilities);
        self::assertContains('sp_manage_widgets', $keys);
        self::assertContains('read', $keys);

        foreach ($capabilities as $capability) {
            self::assertSame(Finding::CONFIDENCE_VERIFIED, $capability->confidence);
            self::assertSame('add_role', $capability->function);
        }
    }

    public function test_add_role_skips_non_literal_capability_keys(): void
    {
        $findings = $this->structures(
            "add_role('sp_editor', 'SP Editor', [\$dynamicCap => true, 'read' => true]);"
        );

        $capabilities = array_values(array_filter(
            $findings,
            static fn (Finding $f): bool => $f->type === 'capability',
        ));

        self::assertCount(1, $capabilities);
        self::assertSame('read', $capabilities[0]->key);
    }

    public function test_add_role_without_literal_capabilities_array_emits_no_capabilities(): void
    {
        $findings = $this->structures("add_role('sp_editor', 'SP Editor', \$caps);");

        $capabilities = array_filter($findings, static fn (Finding $f): bool => $f->type === 'capability');

        self::assertSame([], array_values($capabilities));
    }

    public function test_role_add_cap_method_call(): void
    {
        $finding = $this->firstOfType(
            "\$role = get_role('sp_editor');\n\$role->add_cap('sp_publish_listings');",
            'capability',
        );

        self::assertSame(Finding::CONFIDENCE_VERIFIED, $finding->confidence);
        self::assertSame('sp_publish_listings', $finding->key);
        self::assertSame('add_cap', $finding->function);
    }

    public function test_first_class_callable_add_cap_is_ignored(): void
    {
        self::assertSame([], $this->structures("\$role->add_cap(...);"));
    }
}
