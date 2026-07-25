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
use Sediment\Analyzer\Visitors\MetaVisitor;

/**
 * Drives the symbol table + resolver + meta visitor together over inline
 * snippets. Pins key resolution (§8) and the register_meta() object-type
 * mapping for MetaVisitor.
 */
final class MetaVisitorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function meta(string $body): array
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

        $visitor = new MetaVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    private function firstMeta(string $body): Finding
    {
        $findings = $this->meta($body);
        self::assertNotEmpty($findings, 'expected at least one meta finding');

        return $findings[0];
    }

    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function keyCases(): iterable
    {
        yield 'add_post_meta literal' => [
            "add_post_meta(1, 'mp_flag', true);",
            'post_meta',
            Finding::CONFIDENCE_VERIFIED,
            'mp_flag',
        ];

        yield 'update_post_meta literal' => [
            "update_post_meta(1, 'mp_flag', true);",
            'post_meta',
            Finding::CONFIDENCE_VERIFIED,
            'mp_flag',
        ];

        yield 'add_user_meta literal' => [
            "add_user_meta(1, 'mp_pref', 'v');",
            'user_meta',
            Finding::CONFIDENCE_VERIFIED,
            'mp_pref',
        ];

        yield 'update_user_meta literal' => [
            "update_user_meta(1, 'mp_pref', 'v');",
            'user_meta',
            Finding::CONFIDENCE_VERIFIED,
            'mp_pref',
        ];

        yield 'add_term_meta literal' => [
            "add_term_meta(1, 'mp_term', 'v');",
            'term_meta',
            Finding::CONFIDENCE_VERIFIED,
            'mp_term',
        ];

        yield 'update_term_meta literal' => [
            "update_term_meta(1, 'mp_term', 'v');",
            'term_meta',
            Finding::CONFIDENCE_VERIFIED,
            'mp_term',
        ];

        yield 'add_comment_meta literal' => [
            "add_comment_meta(1, 'mp_comment', 'v');",
            'comment_meta',
            Finding::CONFIDENCE_VERIFIED,
            'mp_comment',
        ];

        yield 'update_comment_meta literal' => [
            "update_comment_meta(1, 'mp_comment', 'v');",
            'comment_meta',
            Finding::CONFIDENCE_VERIFIED,
            'mp_comment',
        ];

        yield 'key from define constant' => [
            "define('MP_PREFIX', 'mp_');\nadd_post_meta(1, MP_PREFIX . 'sync', 'v');",
            'post_meta',
            Finding::CONFIDENCE_RESOLVED,
            'mp_sync',
        ];

        yield 'key from class const' => [
            "class M { const KEY = 'mp_store'; function f() { add_user_meta(1, self::KEY, 'v'); } }",
            'user_meta',
            Finding::CONFIDENCE_RESOLVED,
            'mp_store',
        ];

        yield 'bare variable is dynamic' => [
            "update_comment_meta(1, \$field, 'v');",
            'comment_meta',
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];
    }

    #[DataProvider('keyCases')]
    public function test_key_resolution(string $body, string $type, string $confidence, ?string $key): void
    {
        $finding = $this->firstMeta($body);

        self::assertSame($type, $finding->type);
        self::assertSame($confidence, $finding->confidence);
        self::assertSame($key, $finding->key);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function registerMetaObjectTypeCases(): iterable
    {
        yield 'post' => ["register_meta('post', 'mp_field', []);", 'post_meta'];
        yield 'user' => ["register_meta('user', 'mp_field', []);", 'user_meta'];
        yield 'term' => ["register_meta('term', 'mp_field', []);", 'term_meta'];
        yield 'comment' => ["register_meta('comment', 'mp_field', []);", 'comment_meta'];
    }

    #[DataProvider('registerMetaObjectTypeCases')]
    public function test_register_meta_maps_each_object_type(string $body, string $expectedType): void
    {
        $finding = $this->firstMeta($body);

        self::assertSame($expectedType, $finding->type);
        self::assertSame('register_meta', $finding->function);
        self::assertSame(Finding::CONFIDENCE_VERIFIED, $finding->confidence);
        self::assertSame('mp_field', $finding->key);
    }

    public function test_register_meta_with_unresolvable_object_type_emits_nothing(): void
    {
        self::assertSame([], $this->meta("register_meta(\$type, 'mp_field', []);"));
    }

    public function test_register_meta_with_unknown_object_type_literal_emits_nothing(): void
    {
        // 'menu' is a real-world register_meta object type, but is outside the
        // four WordPress core meta tables (post/user/term/comment) — never guess.
        self::assertSame([], $this->meta("register_meta('menu', 'mp_field', []);"));
    }

    public function test_dynamic_findings_keep_the_raw_expression(): void
    {
        $finding = $this->firstMeta('add_post_meta(1, $this->buildKey($section), $value);');

        self::assertSame(Finding::CONFIDENCE_DYNAMIC, $finding->confidence);
        self::assertNotNull($finding->expression);
        self::assertStringContainsString('buildKey', (string) $finding->expression);
    }
}
