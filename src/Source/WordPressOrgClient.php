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

    /**
     * The largest legitimate plugins unpack to well under 200 MB; an archive
     * claiming gigabytes is a bomb, not a plugin, and extracting it fills the
     * disk mid-batch. Adjustable because the ceiling is a judgement, not a law.
     */
    private const DEFAULT_MAX_UNPACKED_BYTES = 1024 * 1024 * 1024;

    private readonly Http $http;

    public function __construct(
        private readonly string $cacheDirectory,
        ?Http $http = null,
        private readonly int $maxUnpackedBytes = self::DEFAULT_MAX_UNPACKED_BYTES,
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

        [$archive, $fellBack] = $this->download($slug, $version);
        $sha256 = hash('sha256', $archive);

        $this->extract($archive, $slug, $target, $fellBack ? $version : null);
        file_put_contents($checksumFile, $sha256);

        return ['path' => $target, 'version' => $version, 'sha256' => $sha256, 'cached' => false];
    }

    /**
     * Not every plugin keeps per-version archives on wordpress.org — for some,
     * only the unversioned zip of the current release exists (a tenth of the
     * pilot's top-500 fetches hit this). That zip may stand in only when the
     * version asked for IS the current one; anything else would silently
     * deliver different code than was pinned.
     *
     * @return array{0: string, 1: bool} the archive, and whether it is the
     *         unversioned stand-in, whose contents must still prove the version
     */
    private function download(string $slug, string $version): array
    {
        try {
            return [$this->http->get(sprintf(self::DOWNLOAD_URL, $slug, $version)), false];
        } catch (RuntimeException $e) {
            if ($version !== $this->latestVersion($slug)) {
                throw $e;
            }

            return [$this->http->get(sprintf(self::LATEST_URL, $slug)), true];
        }
    }

    /**
     * wordpress.org archives contain a single top-level directory named for the
     * plugin; it is unwrapped so callers get the plugin root directly.
     *
     * @param string|null $expectVersion when set, the plugin header must state
     *        exactly this version, or nothing reaches the cache
     */
    private function extract(string $archive, string $slug, string $target, ?string $expectVersion = null): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Fetching plugins needs the zip extension (ext-zip).');
        }

        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0777, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException("Could not create the cache directory {$this->cacheDirectory}.");
        }

        $temporary = $this->cacheDirectory . '/.' . $slug . '-' . bin2hex(random_bytes(4));
        $archiveFile = $temporary . '.zip';

        if (file_put_contents($archiveFile, $archive) !== strlen($archive)) {
            @unlink($archiveFile);
            throw new RuntimeException("Could not write the archive downloaded for \"{$slug}\".");
        }

        $zip = new \ZipArchive();
        if ($zip->open($archiveFile) !== true) {
            @unlink($archiveFile);
            throw new RuntimeException("Could not open the archive downloaded for \"{$slug}\".");
        }

        try {
            $this->unpack($zip, $slug, $temporary);
        } catch (\Throwable $e) {
            // A half-unpacked plugin must never reach the cache: it would be
            // served as `cached: true` later, and usually grade cleaner than
            // the real one.
            self::remove($temporary);
            throw $e;
        } finally {
            $zip->close();
            @unlink($archiveFile);
        }

        $unwrapped = is_dir($temporary . '/' . $slug) ? $temporary . '/' . $slug : $temporary;

        // The unversioned zip is whatever is current when it is downloaded,
        // which may already be a release newer than the version the API named
        // a moment earlier. Recording those bytes under the pinned version
        // would be a false provenance, so the plugin must say which it is.
        if ($expectVersion !== null) {
            $found = self::headerVersion($unwrapped);
            if ($found !== $expectVersion) {
                self::remove($temporary);
                throw new RuntimeException(sprintf(
                    'The current archive for "%s" is version %s, not the pinned %s, and was refused.',
                    $slug,
                    $found ?? '(no Version header)',
                    $expectVersion,
                ));
            }
        }

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

    /**
     * Refuse before writing anything, then write while counting.
     *
     * The entry table is checked first: an entry named "../x" writes outside
     * the extraction directory on PHP builds that do not sanitise (zip-slip), a
     * symlink entry can point anywhere, and an entry that cannot even be read is
     * no plugin. Its declared sizes give a cheap early refusal — but they are
     * written by whoever made the archive, so the real ceiling is enforced on
     * the bytes actually inflated, one stream at a time.
     */
    private function unpack(\ZipArchive $zip, string $slug, string $temporary): void
    {
        $refuse = static fn (string $why): RuntimeException
            => new RuntimeException("The archive for \"{$slug}\" {$why} and was refused.");

        $names = [];
        $declared = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw $refuse('has an entry that cannot be read');
            }

            $name = str_replace('\\', '/', (string) $stat['name']);
            if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) === 1 || preg_match('/^[a-z]:/i', $name) === 1) {
                throw $refuse('contains an entry that escapes its directory');
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                && $opsys === \ZipArchive::OPSYS_UNIX
                && is_int($attributes)
                && (($attributes >> 16) & 0170000) === 0120000) {
                throw $refuse('contains a symbolic link');
            }

            $names[$i] = $name;
            $declared += (int) $stat['size'];
        }

        if ($declared > $this->maxUnpackedBytes) {
            throw $refuse(sprintf(
                'claims %d MB unpacked, over the %d MB ceiling,',
                intdiv($declared, 1024 * 1024),
                intdiv($this->maxUnpackedBytes, 1024 * 1024),
            ));
        }

        $written = 0;
        foreach ($names as $i => $name) {
            $destination = $temporary . '/' . $name;

            if (str_ends_with($name, '/')) {
                if (!is_dir($destination) && !@mkdir($destination, 0777, true) && !is_dir($destination)) {
                    throw new RuntimeException("Could not create {$destination}.");
                }

                continue;
            }

            $directory = dirname($destination);
            if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException("Could not create {$directory}.");
            }

            $in = $zip->getStreamIndex($i);
            $out = @fopen($destination, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException("Could not unpack \"{$name}\" from the archive for \"{$slug}\".");
            }

            try {
                while (!feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false) {
                        throw $refuse('has an entry that does not inflate');
                    }

                    $written += strlen($chunk);
                    if ($written > $this->maxUnpackedBytes) {
                        throw $refuse(sprintf(
                            'inflates past the %d MB ceiling',
                            intdiv($this->maxUnpackedBytes, 1024 * 1024),
                        ));
                    }

                    if (fwrite($out, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException("Could not write {$destination}.");
                    }
                }
            } finally {
                fclose($in);
                fclose($out);
            }
        }
    }

    /**
     * The `Version:` header of the plugin's main file: the root-level PHP file
     * whose header names the plugin, read the way WordPress reads it (the first
     * 8 KB, with a closing comment marker trimmed off). Null when no file has one.
     */
    private static function headerVersion(string $directory): ?string
    {
        $files = [];
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $head = str_replace("\r", "\n", (string) @file_get_contents($file, false, null, 0, 8192));
            if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*Plugin Name:/mi', $head) !== 1) {
                continue;
            }

            if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*Version:(.*)$/mi', $head, $m) === 1) {
                $version = trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));

                return $version !== '' ? $version : null;
            }
        }

        return null;
    }

    /**
     * Slugs come from the command line and end up in a URL and a path. `\z`,
     * not `$`: `$` also matches before a trailing newline, so "akismet
"
     * would pass.
     */
    private function guardSlug(string $slug): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9\-]*\z/i', $slug) !== 1) {
            throw new RuntimeException("\"{$slug}\" is not a valid plugin slug.");
        }
    }

    /**
     * Versions come from the command line or wordpress.org's API and end up in
     * a URL and a filesystem path, so they get the same treatment as slugs.
     */
    private function guardVersion(string $version): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9.\-]*\z/i', $version) !== 1) {
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
