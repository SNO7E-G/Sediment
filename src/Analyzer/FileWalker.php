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
 * Excludes dependency, build, and test directories by default so a plugin is
 * never blamed for its vendored code (§12). Symlinks are not followed, which
 * keeps the walk bounded and loop-free.
 */
final class FileWalker
{
    /** Directory names skipped by default (M1, §12). */
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
        $this->excludes = $excludes;
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

        $filter = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $current): bool {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), $this->excludes, true);
                }

                return true;
            }
        );

        $files = [];
        foreach (new RecursiveIteratorIterator($filter) as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if ($fileInfo->isFile() && $this->isPhp($fileInfo->getPathname())) {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function isPhp(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php';
    }
}
