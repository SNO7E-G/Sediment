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
    private const LATEST_URL = 'https://downloads.wordpress.org/plugin/%s.zip';

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
        $this->guardVersion($version);

        $target = $this->cacheDirectory . '/' . $slug . '-' . $version;
        $checksumFile = $target . '.sha256';

        if (is_dir($target) && is_file($checksumFile)) {
            // An unreadable or empty checksum record means the cached copy's
            // provenance is unknown; treat it as a miss and fetch again rather
            // than report an empty hash as if it were one.
            $sha256 = file_get_contents($checksumFile);
            if ($sha256 !== false && trim($sha256) !== '') {
                return [
                    'path' => $target,
                    'version' => $version,
                    'sha256' => trim($sha256),
                    'cached' => true,
                ];
            }
        }

        $archive = $this->download($slug, $version);
        $sha256 = hash('sha256', $archive);

        $this->extract($archive, $slug, $target);
        file_put_contents($checksumFile, $sha256);

        return ['path' => $target, 'version' => $version, 'sha256' => $sha256, 'cached' => false];
    }

    /**
     * Not every plugin keeps per-version archives on wordpress.org — for some,
     * only the unversioned zip of the current release exists (a tenth of the
     * pilot's top-500 fetches hit this). That zip may stand in only when the
     * version asked for IS the current one; anything else would silently
     * deliver different code than was pinned.
     */
    private function download(string $slug, string $version): string
    {
        try {
            return $this->http->get(sprintf(self::DOWNLOAD_URL, $slug, $version));
        } catch (RuntimeException $e) {
            if ($version !== $this->latestVersion($slug)) {
                throw $e;
            }

            return $this->http->get(sprintf(self::LATEST_URL, $slug));
        }
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

        // On Windows a virus scanner routinely still holds freshly-extracted
        // files open, which fails the first rename for a reason that clears
        // itself within a second — 54 of the pilot's 500 fetches died this
        // way. Retry briefly before concluding the move truly cannot happen.
        $moved = @rename($unwrapped, $target);
        for ($attempt = 1; !$moved && $attempt <= 5; $attempt++) {
            usleep(200000 * $attempt);
            $moved = @rename($unwrapped, $target);
        }

        if (!$moved) {
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

    /**
     * Versions come from the command line or wordpress.org's API and end up in
     * a URL and a filesystem path, so they get the same treatment as slugs.
     */
    private function guardVersion(string $version): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9.\-]*$/i', $version) !== 1) {
            throw new RuntimeException("\"{$version}\" is not a valid plugin version.");
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
