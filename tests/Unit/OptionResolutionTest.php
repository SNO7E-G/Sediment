<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\SymbolCollector;
use Sediment\Analyzer\SymbolTable;
use Sediment\Analyzer\Visitors\OptionVisitor;

/**
 * Drives the symbol table + resolver + option visitor together over inline
 * snippets. This is where the confidence model (§8) is pinned in detail.
 */
final class OptionResolutionTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function options(string $body): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse("<?php\n" . $body);
        self::assertNotNull($ast);

        $symbols = new SymbolTable();
        $collect = new NodeTraverser();
        $collect->addVisitor(new SymbolCollector($symbols));
        $collect->traverse($ast);
        $symbols->reconcileInheritedProperties();

        $visitor = new OptionVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    private function firstOption(string $body): Finding
    {
        $findings = $this->options($body);
        self::assertNotEmpty($findings, 'expected at least one option finding');

        return $findings[0];
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function keyCases(): iterable
    {
        yield 'literal string' => [
            "add_option('mp_version', '1.0');",
            Finding::CONFIDENCE_VERIFIED,
            'mp_version',
        ];

        yield 'cross-nothing define constant' => [
            "define('MP_PREFIX', 'mp_');\nadd_option(MP_PREFIX . 'settings');",
            Finding::CONFIDENCE_RESOLVED,
            'mp_settings',
        ];

        yield 'self class constant' => [
            "class P { const PREFIX = 'mp_'; function f() { add_option(self::PREFIX . 'opt'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'mp_opt',
        ];

        yield 'named class constant' => [
            "class P { const PREFIX = 'mp_'; }\nadd_option(P::PREFIX . 'opt');",
            Finding::CONFIDENCE_RESOLVED,
            'mp_opt',
        ];

        yield 'property default' => [
            "class P { private \$prefix = 'mp_'; function f() { add_option(\$this->prefix . 'x'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'mp_x',
        ];

        yield 'property assigned in constructor' => [
            "class P { private \$prefix; function __construct() { \$this->prefix = 'mp_'; } function f() { add_option(\$this->prefix . 'x'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'mp_x',
        ];

        yield 'interpolated property' => [
            "class P { private \$prefix = 'mp_'; function f() { add_option(\"{\$this->prefix}logs\"); } }",
            Finding::CONFIDENCE_RESOLVED,
            'mp_logs',
        ];

        yield 'concat with dynamic tail is a pattern' => [
            "add_option('mp_' . \$section);",
            Finding::CONFIDENCE_PATTERN,
            'mp_*',
        ];

        yield 'interpolation with leading literal is a pattern' => [
            "add_option(\"mp_{\$section}\");",
            Finding::CONFIDENCE_PATTERN,
            'mp_*',
        ];

        yield 'interpolation with dynamic lead is dynamic' => [
            "add_option(\"{\$section}_mp\");",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'bare variable is dynamic' => [
            "add_option(\$key);",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'unknown constant is dynamic' => [
            "add_option(UNKNOWN_CONST);",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'parent constant is not tracked, stays dynamic' => [
            "class P { function f() { add_option(parent::PREFIX . 'x'); } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'top-level const resolves' => [
            "const MP_PREFIX = 'mp_';\nadd_option(MP_PREFIX . 'x');",
            Finding::CONFIDENCE_RESOLVED,
            'mp_x',
        ];

        yield 'uppercase function name still matches' => [
            "ADD_OPTION('mp_x', 'v');",
            Finding::CONFIDENCE_VERIFIED,
            'mp_x',
        ];

        yield 'option key by named argument' => [
            "add_option(value: 'v', option: 'real_key');",
            Finding::CONFIDENCE_VERIFIED,
            'real_key',
        ];

        yield 'self constant is early-bound and safe even with a subclass' => [
            "class Base { const PREFIX = 'base_'; function f() { add_option(self::PREFIX . 'x'); } }\nclass Child extends Base {}",
            Finding::CONFIDENCE_RESOLVED,
            'base_x',
        ];

        // --- false-confidence guards: every case below must NOT over-claim ---

        yield 'empty string key is dynamic' => [
            "add_option('');",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'static constant with child override is dynamic' => [
            "class Base { const PREFIX = 'base_'; function f() { add_option(static::PREFIX . 'x'); } }\nclass Child extends Base { const PREFIX = 'child_'; }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'property reassigned dynamically is poisoned' => [
            "class P { private \$prefix; function __construct() { \$this->prefix = 'mp_'; } function init() { \$this->prefix = get_option('slug'); } function f() { add_option(\$this->prefix . 'x'); } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'constructor-parameter property is poisoned' => [
            "class Logger { private \$prefix = 'default_'; function __construct(\$prefix) { \$this->prefix = \$prefix; } function save() { update_option(\$this->prefix . 'log', 1); } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'compound-append property is poisoned' => [
            "class P { private \$prefix = 'mp_'; function init() { \$this->prefix .= foo(); } function f() { add_option(\$this->prefix . 'settings'); } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'conflicting literal property is poisoned' => [
            "class P { function a() { \$this->prefix = 'aaa_'; } function b() { \$this->prefix = 'bbb_'; } function f() { add_option(\$this->prefix . 'x'); } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'promoted constructor property is dynamic' => [
            "class P { function __construct(private string \$prefix = 'mp_') {} function f() { add_option(\$this->prefix . 'x'); } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'conflicting duplicate define is poisoned' => [
            "define('SP', 'one_');\ndefine('SP', 'two_');\nadd_option(SP . 'x');",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'same short-name class in two namespaces poisons self constant' => [
            "namespace A { class Plugin { const PREFIX = 'a_'; function f() { add_option(self::PREFIX . 'x'); } } }\nnamespace B { class Plugin { const PREFIX = 'b_'; } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'overridden inherited property is poisoned' => [
            "class Base { protected \$prefix = 'base_'; function f() { add_option(\$this->prefix . 'x'); } }\nclass Child extends Base { protected \$prefix = 'child_'; }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'symbols do not leak between anonymous classes' => [
            "\$a = new class { private \$prefix = 'aaa_'; };\n\$b = new class { function f() { add_option(\$this->prefix . 'x'); } };",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];
    }

    #[DataProvider('keyCases')]
    public function test_key_resolution(string $body, string $confidence, ?string $key): void
    {
        $finding = $this->firstOption($body);

        self::assertSame($confidence, $finding->confidence);
        self::assertSame($key, $finding->key);
    }

    public function test_dynamic_findings_keep_the_raw_expression(): void
    {
        $finding = $this->firstOption('add_option($this->build($section));');

        self::assertSame(Finding::CONFIDENCE_DYNAMIC, $finding->confidence);
        self::assertNotNull($finding->expression);
        self::assertStringContainsString('build', (string) $finding->expression);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function autoloadCases(): iterable
    {
        yield 'add_option defaults to autoloaded' => ["add_option('k', 'v');", 'yes'];
        yield 'add_option explicit no string' => ["add_option('k', 'v', '', 'no');", 'no'];
        yield 'add_option explicit false bool' => ["add_option('k', 'v', '', false);", 'no'];
        yield 'add_option explicit yes string' => ["add_option('k', 'v', '', 'yes');", 'yes'];
        yield 'update_option without flag is unknown' => ["update_option('k', 'v');", 'unknown'];
        yield 'update_option explicit no' => ["update_option('k', 'v', false);", 'no'];
        yield 'site option has no autoload' => ["add_site_option('k', 'v');", null];
        yield 'add_option autoload by named bool' => ["add_option('k', 'v', autoload: false);", 'no'];
        yield 'add_option autoload by named string' => ["add_option('k', 'v', autoload: 'no');", 'no'];
        yield 'update_option string-form autoload' => ["update_option('k', 'v', 'no');", 'no'];
    }

    #[DataProvider('autoloadCases')]
    public function test_autoload_capture(string $body, ?string $autoload): void
    {
        self::assertSame($autoload, $this->firstOption($body)->autoload);
    }

    public function test_first_class_callable_is_ignored_and_never_throws(): void
    {
        // add_option(...) is a first-class callable, not an option write.
        // It must not crash the scan (M14) and must produce no finding.
        self::assertSame([], $this->options('$fn = add_option(...);'));
    }
}
