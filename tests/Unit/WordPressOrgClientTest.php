<?php

declare(strict_types=1);

namespace Sediment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sediment\Source\Http;
use Sediment\Source\WordPressOrgClient;
use ZipArchive;

/**
 * The downloader, exercised without touching the network: a test suite that
 * depends on someone else's server fails for reasons that are not about this
 * code.
 */
final class WordPressOrgClientTest extends TestCase
{
    private string $cache = '';

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/sediment-fetch-' . getmypid() . '-' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if ($this->cache !== '' && is_dir($this->cache)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cache, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->cache);
        }
    }

    /**
     * A stand-in for wordpress.org that serves a plugin zip shaped the way the
     * real one is: a single top-level directory named for the plugin.
     *
     * @param array<string, string> $responses url fragment => body
     */
    private function http(array $responses): Http
    {
        return new class ($responses) implements Http {
            /** @var list<string> */
            public array $requested = [];

            /** @param array<string, string> $responses */
            public function __construct(private readonly array $responses)
            {
            }

            public function get(string $url): string
            {
                $this->requested[] = $url;

                foreach ($this->responses as $fragment => $body) {
                    if (str_contains($url, $fragment)) {
                        return $body;
                    }
                }

                throw new RuntimeException("unexpected request: {$url}");
            }
        };
    }

    private function pluginZip(string $slug, string $contents): string
    {
        $file = sys_get_temp_dir() . '/sediment-zip-' . bin2hex(random_bytes(4)) . '.zip';

        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE);
        $zip->addFromString($slug . '/' . $slug . '.php', $contents);
        $zip->close();

        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    public function test_it_downloads_a_pinned_version_and_unwraps_the_archive(): void
    {
        $zip = $this->pluginZip('demo-plugin', "<?php\nadd_option('demo_settings', 1);\n");
        $client = new WordPressOrgClient($this->cache, $this->http(['demo-plugin.1.2.3.zip' => $zip]));

        $result = $client->fetch('demo-plugin', '1.2.3');

        self::assertSame('1.2.3', $result['version']);
        self::assertFalse($result['cached']);
        self::assertSame(hash('sha256', $zip), $result['sha256'], 'the archive checksum is what makes a pin verifiable');

        // The plugin root, not the wrapper directory the archive ships.
        self::assertFileExists($result['path'] . '/demo-plugin.php');
    }

    public function test_a_second_fetch_reuses_the_cache_instead_of_downloading_again(): void
    {
        $zip = $this->pluginZip('demo-plugin', "<?php\n");
        $http = $this->http(['demo-plugin.1.2.3.zip' => $zip]);
        $client = new WordPressOrgClient($this->cache, $http);

        $client->fetch('demo-plugin', '1.2.3');
        $second = $client->fetch('demo-plugin', '1.2.3');

        self::assertTrue($second['cached']);
        self::assertCount(1, $http->requested, 'a cached plugin must not be downloaded twice');
    }

    public function test_it_resolves_the_current_version_when_none_is_pinned(): void
    {
        $zip = $this->pluginZip('demo-plugin', "<?php\n");
        $client = new WordPressOrgClient($this->cache, $this->http([
            'plugin_information' => json_encode(['version' => '4.5.6']),
            'demo-plugin.4.5.6.zip' => $zip,
        ]));

        self::assertSame('4.5.6', $client->fetch('demo-plugin')['version']);
    }

    public function test_a_missing_versioned_zip_falls_back_to_the_current_release_zip(): void
    {
        // A tenth of the pilot's top-500 plugins keep no per-version archive on
        // wordpress.org; only the unversioned zip of the current release
        // exists. It may stand in exactly when the pinned version IS current.
        $zip = $this->pluginZip('demo-plugin', "<?php\n");
        $http = $this->http([
            'plugin_information' => json_encode(['version' => '1.9.5']),
            'demo-plugin.zip' => $zip,
        ]);

        $result = (new WordPressOrgClient($this->cache, $http))->fetch('demo-plugin', '1.9.5');

        self::assertSame('1.9.5', $result['version']);
        self::assertFileExists($result['path'] . '/demo-plugin.php');
    }

    public function test_the_fallback_never_substitutes_a_different_version_than_was_pinned(): void
    {
        // The unversioned zip is whatever is current. Serving it for an older
        // pin would silently deliver different code than was asked for.
        $client = new WordPressOrgClient($this->cache, $this->http([
            'plugin_information' => json_encode(['version' => '2.0.0']),
        ]));

        $this->expectException(RuntimeException::class);
        $client->fetch('demo-plugin', '1.9.5');
    }

    public function test_an_archive_entry_that_escapes_its_directory_is_refused_before_extraction(): void
    {
        // Classic zip-slip: an entry named "../x" writes outside the extraction
        // directory on PHP builds that do not sanitise. Refused up front, from
        // the entry table alone.
        $file = sys_get_temp_dir() . '/sediment-slip-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE);
        $zip->addFromString('demo-plugin/demo-plugin.php', "<?php\n");
        $zip->addFromString('../escaped.php', "<?php\n");
        $zip->close();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        $client = new WordPressOrgClient($this->cache, $this->http(['demo-plugin.1.0.zip' => $bytes]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes its directory');
        $client->fetch('demo-plugin', '1.0');
    }

    public function test_an_archive_claiming_more_than_the_unpacked_ceiling_is_refused(): void
    {
        $zip = $this->pluginZip('demo-plugin', "<?php\n" . str_repeat('// padding', 40));
        $client = new WordPressOrgClient($this->cache, $this->http(['demo-plugin.1.0.zip' => $zip]), maxUnpackedBytes: 64);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ceiling');
        $client->fetch('demo-plugin', '1.0');
    }

    public function test_a_hostile_slug_is_refused_before_it_reaches_a_url_or_a_path(): void
    {
        $client = new WordPressOrgClient($this->cache, $this->http([]));

        $this->expectException(RuntimeException::class);
        $client->fetch('../../etc/passwd', '1.0');
    }

    public function test_a_missing_version_in_the_api_response_is_an_error_not_a_guess(): void
    {
        $client = new WordPressOrgClient($this->cache, $this->http(['plugin_information' => json_encode(['error' => 'not found'])]));

        $this->expectException(RuntimeException::class);
        $client->fetch('demo-plugin');
    }
}
