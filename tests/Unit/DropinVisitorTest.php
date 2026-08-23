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
use Sediment\Analyzer\Visitors\DropinVisitor;

/**
 * Pins drop-in and must-use detection (C5/C6): the file writes that outlive
 * the plugin with its boots on, keyed by portable path and named exactly or
 * not at all.
 */
final class DropinVisitorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private function findings(string $body): array
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

        $visitor = new DropinVisitor('inline.php', new ExpressionResolver($symbols));
        $detect = new NodeTraverser();
        $detect->addVisitor($visitor);
        $detect->traverse($ast);

        return $visitor->findings();
    }

    public function test_file_put_contents_to_a_known_dropin_is_detected(): void
    {
        [$finding] = $this->findings("file_put_contents(WP_CONTENT_DIR . '/advanced-cache.php', 'x');");

        self::assertSame('dropin', $finding->type);
        self::assertSame('{content_dir}/advanced-cache.php', $finding->key);
        self::assertSame(Finding::CONFIDENCE_VERIFIED, $finding->confidence);
    }

    public function test_wp_filesystem_put_contents_to_mu_plugins_is_a_muplugin(): void
    {
        [$finding] = $this->findings("\$wp_filesystem->put_contents(WPMU_PLUGIN_DIR . '/acme-loader.php', 'x');");

        self::assertSame('muplugin', $finding->type);
        self::assertSame('{mu_plugins}/acme-loader.php', $finding->key);
    }

    public function test_copy_destination_is_what_persists(): void
    {
        [$finding] = $this->findings("copy(__FILE__, WPMU_PLUGIN_DIR . '/copied.php');");

        self::assertSame('muplugin', $finding->type);
        self::assertSame('{mu_plugins}/copied.php', $finding->key);
    }

    public function test_an_unknown_content_file_is_not_reported(): void
    {
        // wp-content holds plenty of ordinary files; only the names WordPress
        // itself loads are footprint in this sense.
        self::assertSame([], $this->findings("file_put_contents(WP_CONTENT_DIR . '/notes.txt', 'x');"));
    }

    public function test_a_partly_dynamic_filename_cannot_be_attributed(): void
    {
        // The name IS the artifact here; a pattern would be a guess about
        // which file to delete, so nothing is recorded at all.
        self::assertSame([], $this->findings("file_put_contents(WP_CONTENT_DIR . '/cache_' . \$slot . '.php', 'x');"));
    }

    public function test_the_bare_root_names_no_file(): void
    {
        self::assertSame([], $this->findings('file_put_contents(WPMU_PLUGIN_DIR, "x");'));
    }

    public function test_fixture_cleans_both_files_and_grades_A(): void
    {
        $result = (new Scanner())->scan(dirname(__DIR__) . '/fixtures/dropin-plugin');

        $byKey = [];
        foreach ($result['findings'] as $finding) {
            if ($finding->key !== null) {
                $byKey[$finding->type . ':' . $finding->key] = $finding;
            }
        }

        self::assertArrayHasKey('dropin:{content_dir}/advanced-cache.php', $byKey);
        self::assertArrayHasKey('muplugin:{mu_plugins}/dp-loader.php', $byKey);
        self::assertTrue($byKey['dropin:{content_dir}/advanced-cache.php']->cleaned, 'wp_delete_file credits the drop-in');
        self::assertTrue($byKey['muplugin:{mu_plugins}/dp-loader.php']->cleaned, 'unlink credits the mu-plugin');

        $grade = (new \Sediment\Manifest\Grader())->grade($result['findings'], $result['cleanup']);
        self::assertSame('A', $grade->letter);
    }

    public function test_a_left_behind_dropin_caps_the_grade_at_D(): void
    {
        $grade = (new \Sediment\Manifest\Grader())->grade([
            new Finding(
                type: 'dropin',
                function: 'file_put_contents',
                key: '{content_dir}/object-cache.php',
                confidence: Finding::CONFIDENCE_VERIFIED,
                file: 'plugin.php',
                line: 1,
                cleaned: false,
            ),
        ], ['has_uninstall_php' => true, 'has_uninstall_hook' => false]);

        self::assertSame('D', $grade->letter);
        self::assertStringContainsString('drop-in', $grade->summary);
    }
}
