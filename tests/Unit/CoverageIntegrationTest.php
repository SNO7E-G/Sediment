<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Generator\UninstallGenerator;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;

/**
 * The v0.2 artifact types must survive the whole pipeline: detected by the
 * Scanner, grouped in the manifest, weighed by the grader, and either removed or
 * deliberately not removed by the generator.
 */
final class CoverageIntegrationTest extends TestCase
{
    /** @return array{findings: list<Finding>, cleanup: array{has_uninstall_php: bool, has_uninstall_hook: bool}, files: list<string>, errors: list<array{file: string, message: string}>} */
    private function scan(string $fixture): array
    {
        return (new Scanner())->scan(dirname(__DIR__) . '/fixtures/' . $fixture);
    }

    public function test_scanner_emits_the_new_artifact_types(): void
    {
        $types = array_unique(array_map(
            static fn (Finding $f): string => $f->type,
            [...$this->scan('meta-plugin')['findings'], ...$this->scan('structure-plugin')['findings']],
        ));

        foreach (['post_meta', 'role', 'post_type', 'taxonomy'] as $type) {
            self::assertContains($type, $types, "scanner should surface {$type}");
        }
    }

    public function test_manifest_groups_the_new_types_under_their_schema_keys(): void
    {
        $scan = $this->scan('structure-plugin');
        $manifest = Manifest::build($scan, (new Grader())->grade($scan['findings'], $scan['cleanup']), 'x/structure-plugin', '2026-07-26T00:00:00Z');

        self::assertNotEmpty($manifest['creates']['roles']);
        self::assertNotEmpty($manifest['creates']['post_types']);
        self::assertNotEmpty($manifest['creates']['taxonomies']);
        // Still reserved, still present.
        self::assertSame([], $manifest['creates']['directories']);
    }

    public function test_an_orphaned_post_type_caps_the_grade_at_D(): void
    {
        $finding = new Finding(
            type: 'post_type',
            function: 'register_post_type',
            key: 'acme_product',
            confidence: Finding::CONFIDENCE_VERIFIED,
            file: 'plugin.php',
            line: 1,
            cleaned: false,
        );

        $grade = (new Grader())->grade([$finding], ['has_uninstall_php' => true, 'has_uninstall_hook' => false]);

        self::assertSame('D', $grade->letter);
        self::assertStringContainsString('post type', $grade->summary);
    }

    public function test_generator_removes_meta_and_roles_but_never_content(): void
    {
        $make = static fn (string $type, string $key, string $function): Finding => new Finding(
            type: $type,
            function: $function,
            key: $key,
            confidence: Finding::CONFIDENCE_VERIFIED,
            file: 'plugin.php',
            line: 1,
            cleaned: false,
        );

        $code = (new UninstallGenerator())->generate([
            $make('post_meta', '_acme_ref', 'add_post_meta'),
            $make('user_meta', 'acme_pref', 'add_user_meta'),
            $make('role', 'acme_manager', 'add_role'),
            $make('post_type', 'acme_product', 'register_post_type'),
            $make('taxonomy', 'acme_brand', 'register_taxonomy'),
        ], 'Acme');

        self::assertNotNull(
            (new ParserFactory())->createForNewestSupportedVersion()->parse($code),
            'generated uninstall.php must be valid PHP',
        );

        self::assertStringContainsString("delete_post_meta_by_key('_acme_ref');", $code);
        self::assertStringContainsString("delete_metadata('user', 0, 'acme_pref', '', true);", $code);
        self::assertStringContainsString("remove_role('acme_manager');", $code);

        // Content is reported as a comment, never deleted — dropping posts or
        // terms would destroy user data.
        self::assertStringNotContainsString("register_post_type", $code);
        self::assertMatchesRegularExpression('/\/\/\s+- post type "acme_product"/', $code);
        self::assertMatchesRegularExpression('/\/\/\s+- taxonomy "acme_brand"/', $code);
        self::assertStringNotContainsString('wp_delete_post', $code);
    }

    public function test_meta_cleanup_in_uninstall_is_credited(): void
    {
        $scan = $this->scan('meta-clean-plugin');

        $byKey = [];
        foreach ($scan['findings'] as $finding) {
            if ($finding->key !== null) {
                $byKey[$finding->type . ':' . $finding->key] = $finding;
            }
        }

        self::assertTrue($byKey['post_meta:mcp_ref']->cleaned, 'delete_post_meta_by_key in uninstall.php should credit');
        self::assertTrue($byKey['user_meta:mcp_pref']->cleaned, 'delete_metadata in uninstall.php should credit');
        self::assertTrue($byKey['role:mcp_role']->cleaned, 'remove_role in uninstall.php should credit');
    }
}
