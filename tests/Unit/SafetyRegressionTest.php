<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Generator\UninstallGenerator;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;
use Symfony\Component\Process\Process;

/**
 * Regressions from the 0.10.1 safety review: each one a way Sediment named,
 * credited, or emitted something the source did not prove.
 */
final class SafetyRegressionTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
    }

    /**
     * @param array<string, string> $files name => source
     * @return array{files: list<string>, findings: list<Finding>, errors: list<array{file: string, message: string, code: string}>, cleanup: array{has_uninstall_php: bool, has_uninstall_hook: bool, conditional: bool, condition_option: string|null, condition_default: bool|string|null}}
     */
    private function scan(array $files): array
    {
        $this->dir = sys_get_temp_dir() . '/sediment-safety-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0777, true);
        foreach ($files as $name => $source) {
            file_put_contents($this->dir . '/' . $name, $source);
        }

        return (new Scanner())->scan($this->dir);
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, Finding> "type:key" => finding
     */
    private static function byKey(array $findings): array
    {
        $byKey = [];
        foreach ($findings as $finding) {
            if ($finding->key !== null) {
                $byKey[$finding->type . ':' . $finding->key] = $finding;
            }
        }

        return $byKey;
    }

    public function test_a_wrapper_that_rewrites_its_parameter_is_not_expanded_to_the_callers_literal(): void
    {
        // The callers pass 'enabled' and 'color', but the options written are
        // 'myplug_enabled' and sanitize_key('color'). Naming the callers'
        // literals would put another plugin's 'enabled' into uninstall.php.
        $scan = $this->scan(['plugin.php' => <<<'PHP'
            <?php
            class Opts {
                public static function set($name, $v) { $name = 'myplug_' . $name; update_option($name, $v); }
                public static function set2($key, $v) { $key = sanitize_key($key); update_option($key, $v); }
                public static function set3($key, $v) { update_option($key, $v); }
            }
            Opts::set('enabled', 1);
            Opts::set2('color', 1);
            Opts::set3('untouched_key', 1);
            PHP]);

        $byKey = self::byKey($scan['findings']);
        self::assertArrayNotHasKey('option:enabled', $byKey);
        self::assertArrayNotHasKey('option:color', $byKey);
        self::assertArrayHasKey('option:untouched_key', $byKey, 'a parameter passed straight through still expands');
    }

    public function test_a_first_class_callable_does_not_cost_the_file_its_findings(): void
    {
        // getArgs() asserts on strlen(...); with assertions on, that one throw
        // used to discard every finding in the file.
        $previous = ini_get('zend.assertions');
        @ini_set('zend.assertions', '1');

        try {
            $scan = $this->scan(['plugin.php' => "<?php\nupdate_option('fcc_a', 1);\n\$f = strlen(...);\nupdate_post_meta(1, 'fcc_m', 2);\ncopy(...);\n"]);
        } finally {
            @ini_set('zend.assertions', (string) $previous);
        }

        self::assertSame([], $scan['errors']);
        $byKey = self::byKey($scan['findings']);
        self::assertArrayHasKey('option:fcc_a', $byKey);
        self::assertArrayHasKey('post_meta:fcc_m', $byKey);
    }

    public function test_a_single_object_meta_delete_does_not_credit_the_key_everywhere(): void
    {
        $scan = $this->scan([
            'plugin.php' => "<?php\nupdate_post_meta(1, 'one_m', 1);\nupdate_user_meta(1, 'one_u', 1);\nupdate_post_meta(1, 'all_m', 1);\nupdate_user_meta(1, 'loop_u', 1);\n",
            'uninstall.php' => <<<'PHP'
                <?php
                if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
                delete_post_meta(5, 'one_m');
                delete_metadata('user', 5, 'one_u');
                delete_metadata('post', 0, 'all_m', '', true);
                foreach (get_users() as $user) { delete_user_meta($user->ID, 'loop_u'); }
                PHP,
        ]);

        $byKey = self::byKey($scan['findings']);
        self::assertFalse($byKey['post_meta:one_m']->cleaned, 'one post loses the key; every other keeps it');
        self::assertFalse($byKey['user_meta:one_u']->cleaned);
        self::assertTrue($byKey['post_meta:all_m']->cleaned, '$delete_all clears every object');
        self::assertTrue($byKey['user_meta:loop_u']->cleaned, 'a loop over the objects clears them all');
    }

    public function test_invalid_utf8_in_a_key_still_produces_a_manifest(): void
    {
        // A Latin-1 source file. json_encode used to fail, the manifest came out
        // empty, and the scan still exited 0.
        $scan = $this->scan(['plugin.php' => "<?php\nupdate_option('caf\xE9', 1);\n"]);
        $grade = (new Grader())->grade($scan['findings'], $scan['cleanup']);

        $json = Manifest::toJson(Manifest::build($scan, $grade, $this->dir ?? 'x', '2026-10-19T00:00:00Z'));
        self::assertIsArray(json_decode($json, true));
        self::assertStringContainsString("\"key\": \"caf\u{FFFD}\"", $json);
    }

    public function test_a_name_cannot_break_out_of_a_generated_comment(): void
    {
        /*
         * A closing PHP tag ends a // comment and leaves PHP mode; an opening tag
         * after it would be live code in a file people run on uninstall. (This
         * very comment had to become a block comment for the same reason.)
         */
        $finding = static fn (string $type, string $key): Finding => new Finding(
            type: $type,
            function: 'f',
            key: $key,
            confidence: Finding::CONFIDENCE_VERIFIED,
            file: 'p.php',
            line: 1,
        );

        $code = (new UninstallGenerator())->generate([
            $finding('post_type', 'x?><?php echo "PWNED"; //'),
            $finding('capability', 'cap?><?php echo "CAP"; //'),
        ], 'inject');

        self::assertStringNotContainsString('?>', $code);

        $file = sys_get_temp_dir() . '/sediment-generated-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($file, $code);
        try {
            $process = new Process([\PHP_BINARY, '-r', 'define("WP_UNINSTALL_PLUGIN", 1); include $argv[1];', $file]);
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();
        } finally {
            @unlink($file);
        }

        self::assertSame('', $output, 'the generated file must run without printing anything');
    }
}
