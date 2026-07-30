<?php

declare(strict_types=1);

namespace Sediment\Source;

use RuntimeException;

/**
 * Downloads plugins from wordpress.org, by pinned version, into a local cache.
 *
 * The golden corpus depends on fetching *exactly* the same bytes every run —
 * a corpus pinned to "latest" would rewrite its own expectations every time an
 * author shipped a release, which is the opposite of a regression net. So every
 * download records the version and a sha256 of the archive, and a cached copy is
 * reused rather than re-fetched.
 */
final class WordPressOrgClient
{
    private const INFO_URL = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=%s';
    private const DOWNLOAD_URL = 'https://downloads.wordpress.org/plugin/%s.%s.zip';

    private readonly Http $http;

    public function __construct(
        private readonly string $cacheDirectory,
        ?Http $http = null,
    ) {
        $this->http = $http ?? new CurlHttp();
    }

    /** The version wordpress.org currently considers stable. */
    public function latestVersion(string $slug): string
    {
        $body = $this->http->get(sprintf(self::INFO_URL, rawurlencode($slug)));
        $info = json_decode($body, true);

        if (!is_array($info) || !isset($info['version']) || !is_string($info['version'])) {
            throw new RuntimeException("wordpress.org returned no version for \"{$slug}\".");
        }

        return $info['version'];
    }

    /**
     * Ensure a specific version is present locally, and return its directory.
     *
     * @return array{path: string, version: string, sha256: string, cached: bool}
     */
    public function fetch(string $slug, ?string $version = null): array
    {
        $this->guardSlug($slug);
        $version ??= $this->latestVersion($slug);

        $target = $this->cacheDirectory . '/' . $slug . '-' . $version;
        $checksumFile = $target . '.sha256';

        if (is_dir($target) && is_file($checksumFile)) {
            return [
                'path' => $target,
                'version' => $version,
                'sha256' => trim((string) file_get_contents($checksumFile)),
                'cached' => true,
            ];
        }

        $archive = $this->http->get(sprintf(self::DOWNLOAD_URL, $slug, $version));
        $sha256 = hash('sha256', $archive);

        $this->extract($archive, $slug, $target);
        file_put_contents($checksumFile, $sha256);

        return ['path' => $target, 'version' => $version, 'sha256' => $sha256, 'cached' => false];
    }

    /**
     * wordpress.org archives contain a single top-level directory named for the
     * plugin; it is unwrapped so callers get the plugin root directly.
     */
    private function extract(string $archive, string $slug, string $target): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Fetching plugins needs the zip extension (ext-zip).');
        }

        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0777, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException("Could not create the cache directory {$this->cacheDirectory}.");
        }

        $temporary = $this->cacheDirectory . '/.' . $slug . '-' . bin2hex(random_bytes(4));
        $archiveFile = $temporary . '.zip';

        file_put_contents($archiveFile, $archive);

        $zip = new \ZipArchive();
        if ($zip->open($archiveFile) !== true) {
            @unlink($archiveFile);
            throw new RuntimeException("Could not open the archive downloaded for \"{$slug}\".");
        }

        $zip->extractTo($temporary);
        $zip->close();
        @unlink($archiveFile);

        $unwrapped = is_dir($temporary . '/' . $slug) ? $temporary . '/' . $slug : $temporary;

        if (is_dir($target)) {
            self::remove($target);
        }

        if (!@rename($unwrapped, $target)) {
            self::remove($temporary);
            throw new RuntimeException("Could not move the extracted plugin into {$target}.");
        }

        if ($unwrapped !== $temporary) {
            self::remove($temporary);
        }
    }

    /** Slugs come from the command line and end up in a URL and a path. */
    private function guardSlug(string $slug): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $slug) !== 1) {
            throw new RuntimeException("\"{$slug}\" is not a valid plugin slug.");
        }
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
