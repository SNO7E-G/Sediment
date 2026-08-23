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

        $names = new NodeTraverser();
        $names->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false]));
        $ast = $names->traverse($ast);

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

        yield 'same short-name class in two namespaces resolves each correctly' => [
            "namespace A { class Plugin { const PREFIX = 'a_'; function f() { add_option(self::PREFIX . 'x'); } } }\nnamespace B { class Plugin { const PREFIX = 'b_'; } }",
            Finding::CONFIDENCE_RESOLVED,
            'a_x',
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

        // --- local-variable binding: only a plain literal assignment resolves ---

        yield 'local variable assigned a literal resolves' => [
            "function f() { \$k = 'lv_key'; add_option(\$k, 1); }",
            Finding::CONFIDENCE_RESOLVED,
            'lv_key',
        ];

        yield 'function parameter is dynamic' => [
            "function f(\$key) { update_option(\$key, 1); }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'parameter conditionally reassigned stays dynamic' => [
            "function f(\$key) { if (\$key === null) { \$key = 'fallback'; } update_option(\$key, 1); }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'foreach binding is dynamic' => [
            "function f(array \$keys) { foreach (\$keys as \$key) { update_option(\$key, 1); } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'global variable is dynamic' => [
            "function f() { global \$key; update_option(\$key, 1); }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        // --- inheritance-aware resolution (0.4.0) ---

        yield 'static constant resolves when no subclass overrides it' => [
            "class Base { const PREFIX = 'base_'; function f() { add_option(static::PREFIX . 'x'); } }\nclass Child extends Base {}",
            Finding::CONFIDENCE_RESOLVED,
            'base_x',
        ];

        yield 'static constant stays dynamic when a subclass overrides it' => [
            "class Base { const PREFIX = 'base_'; function f() { add_option(static::PREFIX . 'x'); } }\nclass Child extends Base { const PREFIX = 'child_'; }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'a constant inherited from a parent resolves' => [
            "class Base { const PREFIX = 'base_'; }\nclass Child extends Base { function f() { add_option(self::PREFIX . 'x'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'base_x',
        ];

        yield 'parent constant resolves through the class hierarchy' => [
            "class Base { const PREFIX = 'base_'; }\nclass Child extends Base { function f() { add_option(parent::PREFIX . 'x'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'base_x',
        ];

        yield 'static property resolves via self' => [
            "class P { private static \$prefix = 'sp_'; function f() { add_option(self::\$prefix . 'x'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'sp_x',
        ];

        yield 'static property resolves via static' => [
            "class P { private static \$prefix = 'sp_'; function f() { add_option(static::\$prefix . 'x'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'sp_x',
        ];

        yield 'a poisoned static property stays dynamic' => [
            "class P { private static \$prefix = 'sp_'; function init() { self::\$prefix = foo(); } function f() { add_option(self::\$prefix . 'x'); } }",
            Finding::CONFIDENCE_DYNAMIC,
            null,
        ];

        yield 'a subclass reading its own overriding property resolves to its value' => [
            "class Base { protected \$prefix = 'base_'; }\nclass Child extends Base { protected \$prefix = 'child_'; function f() { add_option(\$this->prefix . 'x'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'child_x',
        ];

        yield 'a property inherited from a parent resolves' => [
            "class Base { protected \$prefix = 'base_'; }\nclass Child extends Base { function f() { add_option(\$this->prefix . 'x'); } }",
            Finding::CONFIDENCE_RESOLVED,
            'base_x',
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

    public function test_first_class_callable_is_recorded_as_dynamic(): void
    {
        // add_option(...) is a first-class callable: a real write whose key
        // only exists at call time. It is recorded as dynamic so coverage
        // counts it — never silently dropped, never guessed at.
        $findings = $this->options('$fn = add_option(...);');

        self::assertCount(1, $findings);
        self::assertSame(Finding::CONFIDENCE_DYNAMIC, $findings[0]->confidence);
        self::assertNull($findings[0]->key);
        self::assertSame('add_option', $findings[0]->function);
    }

    public function test_trait_constants_resolve_for_the_using_class(): void
    {
        // Traits compose their members into the class that uses them — PHP's
        // own lookup rule — so self::SLUG through a used trait resolves.
        $finding = $this->firstOption(
            "trait Has_Keys { const SLUG = 'tk'; }\n"
            . "class Consumer { use Has_Keys;\n"
            . "    public function boot(): void { update_option(self::SLUG . '_active', true); } }",
        );

        self::assertSame(Finding::CONFIDENCE_RESOLVED, $finding->confidence);
        self::assertSame('tk_active', $finding->key);
    }

    public function test_trait_property_defaults_resolve_for_the_using_class(): void
    {
        $finding = $this->firstOption(
            "trait With_Prefix { protected \$prefix = 'tp_'; }\n"
            . "class Uses_Trait { use With_Prefix;\n"
            . "    public function boot(): void { add_option(\$this->prefix . 'mode', 'on'); } }",
        );

        self::assertSame(Finding::CONFIDENCE_RESOLVED, $finding->confidence);
        self::assertSame('tp_mode', $finding->key);
    }

    public function test_magic_class_constant_resolves_as_a_key_seed(): void
    {
        $finding = $this->firstOption(
            "class Acme { public function boot(): void { add_option(__CLASS__ . '_version', '1'); } }",
        );

        self::assertSame(Finding::CONFIDENCE_RESOLVED, $finding->confidence);
        self::assertSame('Acme_version', $finding->key);
    }

    public function test_class_name_constant_resolves(): void
    {
        $finding = $this->firstOption(
            "class Acme { public function boot(): void { update_option(Acme::class . '_active', true); } }",
        );

        self::assertSame(Finding::CONFIDENCE_RESOLVED, $finding->confidence);
        self::assertSame('Acme_active', $finding->key);
    }

    public function test_magic_method_constant_resolves_inside_a_class(): void
    {
        $finding = $this->firstOption(
            "class Acme { public function boot(): void { add_option('ran_' . __METHOD__, 1); } }",
        );

        self::assertSame(Finding::CONFIDENCE_RESOLVED, $finding->confidence);
        self::assertSame('ran_Acme::boot', $finding->key);
    }
}
