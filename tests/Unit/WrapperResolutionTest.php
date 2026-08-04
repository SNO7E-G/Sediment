<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\CallSites;
use Sediment\Analyzer\ExpressionResolver;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\SymbolCollector;
use Sediment\Analyzer\SymbolTable;
use Sediment\Analyzer\Visitors\OptionVisitor;
use Sediment\Analyzer\WrapperExpander;

/**
 * One-hop wrapper resolution (§0.7).
 *
 * The corpus showed the largest plugins were the ones Sediment could say least
 * about, because they funnel writes through their own settings layer: the call
 * it sees is `update_option($key, ...)` and the keys it needs are at the call
 * sites of the method containing it.
 */
final class WrapperResolutionTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function scan(string $body): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse("<?php\n" . $body);
        self::assertNotNull($ast);

        $names = new NodeTraverser();
        $names->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false]));
        $ast = $names->traverse($ast);

        $symbols = new SymbolTable();
        $callSites = new CallSites();
        $collect = new NodeTraverser();
        $collect->addVisitor(new SymbolCollector($symbols, 'inline.php', $callSites));
        $collect->traverse($ast);
        $symbols->reconcileInheritedProperties();

        $visitor = new OptionVisitor('inline.php', new ExpressionResolver($symbols), $callSites);
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        $findings = [];
        foreach ($visitor->findings() as $finding) {
            foreach (WrapperExpander::expand($finding, $visitor->expansionsAt($finding->line)) as $expanded) {
                $findings[] = $expanded;
            }
        }

        return $findings;
    }

    /** @param list<Finding> $findings */
    private function keys(array $findings): array
    {
        $keys = array_map(static fn (Finding $f): ?string => $f->key, $findings);
        sort($keys);

        return $keys;
    }

    public function test_a_write_through_a_settings_helper_resolves_to_the_keys_its_callers_pass(): void
    {
        // The shape Yoast and Contact Form 7 use, and the reason both scored
        // worst in the corpus.
        $findings = $this->scan(<<<'PHP'
            class Options_Helper {
                public function set($key, $value) {
                    update_option($key, $value);
                }
            }
            class Plugin {
                public function boot() {
                    Options_Helper::set('acme_version', '1.0');
                }
            }
            PHP);

        self::assertSame(['acme_version'], $this->keys($findings));
        self::assertSame(Finding::CONFIDENCE_RESOLVED, $findings[0]->confidence);
    }

    public function test_one_wrapper_expands_into_every_key_it_is_called_with(): void
    {
        $findings = $this->scan(<<<'PHP'
            class Settings {
                private function put($name) {
                    update_option($name, 1);
                }
                public function boot() {
                    $this->put('acme_alpha');
                    $this->put('acme_beta');
                    $this->put('acme_alpha');
                }
            }
            PHP);

        // Three calls, two distinct keys, one write site.
        self::assertSame(['acme_alpha', 'acme_beta'], $this->keys($findings));
    }

    public function test_a_plain_function_wrapper_is_followed_too(): void
    {
        $findings = $this->scan(<<<'PHP'
            function acme_set($key) {
                add_option($key, 'x');
            }
            acme_set('acme_flag');
            PHP);

        self::assertSame(['acme_flag'], $this->keys($findings));
    }

    public function test_an_unreadable_call_site_keeps_the_unresolved_write_in_the_report(): void
    {
        // Reporting only the literal would let a wrapper called once with a key
        // and once with a runtime value read as fully understood.
        $findings = $this->scan(<<<'PHP'
            class Settings {
                private function put($name) {
                    update_option($name, 1);
                }
                public function boot($input) {
                    $this->put('acme_known');
                    $this->put($input);
                }
            }
            PHP);

        self::assertSame([null, 'acme_known'], $this->keys($findings));
        self::assertContains(
            Finding::CONFIDENCE_DYNAMIC,
            array_map(static fn (Finding $f): string => $f->confidence, $findings),
        );
    }

    public function test_a_prefix_the_class_knows_joins_the_name_the_caller_supplies(): void
    {
        // The shape Yoast actually writes: `self::$meta_prefix . $key`. The
        // prefix is resolvable here, the name only at the call sites, and the
        // real artifact is the two joined.
        $findings = $this->scan(<<<'PHP'
            class Meta {
                const PREFIX = 'wpseo_';
                private function put($key) {
                    update_option(self::PREFIX . $key, 1);
                }
                public function boot() {
                    $this->put('title');
                    $this->put('desc');
                }
            }
            PHP);

        self::assertSame(['wpseo_desc', 'wpseo_title'], $this->keys($findings));
    }

    public function test_a_key_built_from_two_unknowns_stays_unresolved(): void
    {
        // With nothing resolvable to anchor it, there is no key to report.
        $findings = $this->scan(<<<'PHP'
            class Settings {
                private function put($prefix, $key) {
                    update_option($prefix . $key, 1);
                }
                public function boot($runtime) {
                    $this->put($runtime, 'x');
                }
            }
            PHP);

        self::assertSame([null], $this->keys($findings));
    }

    public function test_a_variadic_wrapper_is_left_alone(): void
    {
        // With a variadic parameter, position no longer identifies a value.
        $findings = $this->scan(<<<'PHP'
            class Settings {
                private function put(...$args) {
                    update_option($args, 1);
                }
                public function boot() {
                    $this->put('acme_thing');
                }
            }
            PHP);

        self::assertSame([null], $this->keys($findings));
    }

    public function test_wrapping_does_not_reach_across_two_hops(): void
    {
        // Deliberately one hop: the win drops off sharply and the chance of
        // naming the wrong artifact climbs.
        $findings = $this->scan(<<<'PHP'
            class Settings {
                private function inner($key) {
                    update_option($key, 1);
                }
                private function outer($key) {
                    $this->inner($key);
                }
                public function boot() {
                    $this->outer('acme_deep');
                }
            }
            PHP);

        self::assertSame([null], $this->keys($findings));
    }
}
