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
use Sediment\Analyzer\Visitors\FilesystemVisitor;

/**
 * Drives the symbol table + resolver + filesystem visitor together over
 * inline snippets. Pins key resolution (§8) and the WP_CONTENT_DIR /
 * WP_PLUGIN_DIR / ABSPATH placeholder rewrite for FilesystemVisitor.
 */
final class FilesystemVisitorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function directories(string $body): array
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

        $visitor = new FilesystemVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    private function firstDirectory(string $body): Finding
    {
        $findings = $this->directories($body);
        self::assertNotEmpty($findings, 'expected at least one directory finding');

        return $findings[0];
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function keyCases(): iterable
    {
        yield 'wp_mkdir_p literal absolute path' => [
            "wp_mkdir_p('/var/www/html/wp-content/uploads/fsf-static');",
            Finding::CONFIDENCE_VERIFIED,
            '/var/www/html/wp-content/uploads/fsf-static',
        ];

        yield 'mkdir literal absolute path' => [
            "mkdir('/var/www/html/wp-content/uploads/fsf-static', 0755, true);",
            Finding::CONFIDENCE_VERIFIED,
            '/var/www/html/wp-content/uploads/fsf-static',
        ];

        yield 'WP_CONTENT_DIR root with a literal remainder' => [
            "wp_mkdir_p(WP_CONTENT_DIR . '/acme-logs');",
            Finding::CONFIDENCE_VERIFIED,
            '{content_dir}/acme-logs',
        ];

        yield 'WP_CONTENT_DIR root with a define()-built remainder' => [
            "define('FSF_CACHE_DIR', 'fsf-cache');\nwp_mkdir_p(WP_CONTENT_DIR . '/' . FSF_CACHE_DIR);",
            Finding::CONFIDENCE_RESOLVED,
            '{content_dir}/fsf-cache',
        ];

        yield 'WP_CONTENT_DIR root with a partially-resolved remainder is a pattern' => [
            "wp_mkdir_p(WP_CONTENT_DIR . '/uploads/' . \$suffix);",
            Finding::CONFIDENCE_PATTERN,
            '{content_dir}/uploads/*',
        ];

        yield 'WP_PLUGIN_DIR root' => [
            "mkdir(WP_PLUGIN_DIR . '/acme/cache');",
            Finding::CONFIDENCE_VERIFIED,
            '{plugin_dir}/acme/cache',
        ];

        yield 'ABSPATH root' => [
            "mkdir(ABSPATH . 'acme-uploads');",
            Finding::CONFIDENCE_VERIFIED,
            '{abspath}acme-uploads',
        ];

        yield 'bare variable is dynamic' => [
            "mkdir(\$target);",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];
    }

    #[DataProvider('keyCases')]
    public function test_key_resolution(string $body, string $confidence, ?string $key): void
    {
        $finding = $this->firstDirectory($body);

        self::assertSame('directory', $finding->type);
        self::assertSame($confidence, $finding->confidence);
        self::assertSame($key, $finding->key);
    }

    public function test_bare_root_constant_emits_nothing(): void
    {
        // WP_CONTENT_DIR alone would name wp-content itself — never meaningful.
        self::assertSame([], $this->directories('wp_mkdir_p(WP_CONTENT_DIR);'));
    }

    public function test_root_with_a_resolved_empty_remainder_emits_nothing(): void
    {
        self::assertSame(
            [],
            $this->directories("define('FSF_EMPTY', '');\nwp_mkdir_p(WP_CONTENT_DIR . FSF_EMPTY);"),
        );
    }

    public function test_dynamic_remainder_past_a_root_falls_back_to_the_full_expression(): void
    {
        $finding = $this->firstDirectory('wp_mkdir_p(WP_CONTENT_DIR . $slug);');

        self::assertSame(Finding::CONFIDENCE_DYNAMIC, $finding->confidence);
        self::assertNull($finding->key);
        self::assertNotNull($finding->expression);
        self::assertStringContainsString('WP_CONTENT_DIR', (string) $finding->expression);
    }

    public function test_dynamic_findings_keep_the_raw_expression(): void
    {
        $finding = $this->firstDirectory('mkdir($this->buildPath());');

        self::assertSame(Finding::CONFIDENCE_DYNAMIC, $finding->confidence);
        self::assertNotNull($finding->expression);
        self::assertStringContainsString('buildPath', (string) $finding->expression);
    }
}
