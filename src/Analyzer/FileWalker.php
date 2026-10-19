<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Walks a plugin directory and returns the set of PHP files to analyze (M1).
 *
 * Excludes dependency, build, and test directories by default, matched without
 * regard to case, so a plugin is never blamed for its vendored code (§12). The
 * one exception is a block's `render.php`: WordPress loads it straight from
 * `build/<block>/`, so it is plugin code that runs. Symlinks are not followed,
 * which keeps the walk bounded and loop-free.
 */
final class FileWalker
{
    /** Directory names skipped by default (M1, §12), compared lowercase. */
    public const DEFAULT_EXCLUDES = [
        'vendor',
        'node_modules',
        'tests',
        'test',
        'dist',
        'build',
        '.git',
        '.github',
    ];

    /** @var list<string> */
    private array $excludes;

    /** @param list<string> $excludes */
    public function __construct(array $excludes = self::DEFAULT_EXCLUDES)
    {
        $this->excludes = array_map('strtolower', $excludes);
    }

    /**
     * Return absolute paths of every PHP file under $root, sorted for
     * deterministic output. A file path returns just that file.
     *
     * @return list<string>
     */
    public function walk(string $root): array
    {
        $root = rtrim($root, "/\\");

        if (is_file($root)) {
            return $this->isPhp($root) ? [$root] : [];
        }

        if (!is_dir($root)) {
            return [];
        }

        $directoryIterator = new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
        );

        $skipsBuild = in_array('build', $this->excludes, true);

        $filter = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $current) use ($root, $skipsBuild): bool {
                $name = strtolower($current->getFilename());

                if ($current->isDir()) {
                    // A build directory is entered, but only for render.php.
                    return !in_array($name, $this->excludes, true) || ($skipsBuild && $name === 'build');
                }

                if ($current->isLink()) {
                    return false;
                }

                return !$skipsBuild || $name === 'render.php' || !$this->insideBuild($root, $current->getPath());
            }
        );

        // CATCH_GET_CHILD keeps an unreadable subdirectory (permissions) from
        // aborting the whole walk — the scan must never fatal (M14).
        $iterator = new RecursiveIteratorIterator(
            $filter,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $files = [];
        try {
            foreach ($iterator as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                if ($fileInfo->isFile() && $this->isPhp($fileInfo->getPathname())) {
                    // Separators are normalised before sorting, not after: '\'
                    // and '/' sort differently against the characters between
                    // them, so "admin\x.php" and "admin/x.php" land either side
                    // of "adminA.php". Left alone, the same plugin scans in a
                    // different order on Windows and Linux, which reorders every
                    // findings list and makes a manifest platform-dependent.
                    $files[] = str_replace('\\', '/', $fileInfo->getPathname());
                }
            }
        } catch (\Throwable) {
            // Return whatever was collected rather than failing the scan.
        }

        sort($files);

        return $files;
    }

    private function insideBuild(string $root, string $directory): bool
    {
        $relative = str_replace('\\', '/', substr($directory, strlen($root)));

        return in_array('build', explode('/', strtolower($relative)), true);
    }

    private function isPhp(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php';
    }
}
