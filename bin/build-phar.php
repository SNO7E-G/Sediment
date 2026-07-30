#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds a self-contained sediment.phar.
 *
 * A PHAR is how someone gets Sediment without Composer, a vendor directory, or
 * any knowledge of PHP packaging — download one file and run it. That matters
 * for an audit tool whose audience includes people who do not otherwise write
 * PHP.
 *
 * Run with:  php -d phar.readonly=0 bin/build-phar.php
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Could not locate the project root.\n");
    exit(1);
}
$root .= DIRECTORY_SEPARATOR;

define('ROOT', $root);
define('OUTPUT', ROOT . 'build/sediment.phar');

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "PHARs cannot be written while phar.readonly is on.\nRun: php -d phar.readonly=0 bin/build-phar.php\n");
    exit(1);
}

if (!is_file(ROOT . 'vendor/autoload.php')) {
    fwrite(STDERR, "Dependencies are missing. Run: composer install --no-dev\n");
    exit(1);
}

// Shipping PHPUnit inside the binary would roughly double it for no purpose.
if (is_dir(ROOT . 'vendor/phpunit')) {
    fwrite(STDERR, "Warning: dev dependencies are installed; the PHAR will be larger than a release build.\n");
    fwrite(STDERR, "For a release build run: composer install --no-dev --optimize-autoloader\n");
}

@mkdir(dirname(OUTPUT), 0777, true);
@unlink(OUTPUT);

$phar = new Phar(OUTPUT, 0, 'sediment.phar');
$phar->startBuffering();

$added = 0;
foreach (['src', 'vendor'] as $directory) {
    $files = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(ROOT . $directory, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current): bool {
                if ($current->isDir()) {
                    // Test suites and version-control metadata are dead weight in a binary.
                    return !in_array($current->getFilename(), ['tests', 'test', 'Tests', 'docs', '.git', '.github'], true);
                }

                // Not only PHP: Symfony's completion command reads shell scripts
                // out of its Resources directory at construction time, so a
                // PHP-only archive fatals before any command runs.
                return $current->getExtension() === 'php'
                    || str_contains(str_replace('\\', '/', $current->getPathname()), '/Resources/');
            },
        ),
    );

    // buildFromIterator adds the whole set in one pass; adding files one at a
    // time re-writes the archive on each call and takes minutes rather than
    // seconds for a tree this size.
    $mapped = [];
    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        $mapped[str_replace('\\', '/', substr($file->getPathname(), strlen(ROOT)))] = $file->getPathname();
    }

    $phar->buildFromIterator(new ArrayIterator($mapped));
    $added += count($mapped);
}

// The stub boots the application directly rather than including bin/sediment,
// whose shebang line would be echoed as output from inside a PHAR.
$phar->setStub(<<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('sediment.phar');
require 'phar://sediment.phar/vendor/autoload.php';
exit((new Sediment\Application())->run());
__HALT_COMPILER();
STUB);

$phar->stopBuffering();

@chmod(OUTPUT, 0755);

printf("Built %s from %d files (%.1f MB).\n", realpath(OUTPUT), $added, filesize(OUTPUT) / 1048576);
