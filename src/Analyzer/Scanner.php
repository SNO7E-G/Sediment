<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Sediment\Analyzer\Visitors\AbstractDetectionVisitor;
use Sediment\Analyzer\Visitors\CronVisitor;
use Sediment\Analyzer\Visitors\FilesystemVisitor;
use Sediment\Analyzer\Visitors\MetaVisitor;
use Sediment\Analyzer\Visitors\ScheduleVisitor;
use Sediment\Analyzer\Visitors\OptionVisitor;
use Sediment\Analyzer\Visitors\RewriteVisitor;
use Sediment\Analyzer\Visitors\StructureVisitor;
use Sediment\Analyzer\Visitors\TableVisitor;
use Sediment\Analyzer\Visitors\TransientVisitor;
use Sediment\Cleanup\CleanupDiffer;
use Sediment\Cleanup\CleanupVisitor;

/**
 * Orchestrates the scan in two passes (§12):
 *  1. Parse every file, resolve namespaced names to fully-qualified ones, and
 *     harvest literal symbols into a {@see SymbolTable} — so a constant defined
 *     in one file resolves a key written in another.
 *  2. Re-walk each file with the detection and cleanup visitors, resolving keys
 *     against the symbol table, then diff creates against removals.
 *
 * Nothing ever fatals (M14): a malformed or unreadable file, or an unexpected
 * node, is recorded and skipped.
 */
final class Scanner
{
    private Parser $parser;

    public function __construct(
        private readonly FileWalker $walker = new FileWalker(),
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return array{
     *     files: list<string>,
     *     findings: list<Finding>,
     *     errors: list<array{file: string, message: string}>,
     *     cleanup: array{has_uninstall_php: bool, has_uninstall_hook: bool, conditional: bool, condition_option: string|null, condition_default: bool|string|null}
     * }
     */
    public function scan(string $root): array
    {
        $files = $this->walker->walk($root);

        $symbols = new SymbolTable();
        $callSites = new CallSites();
        $errors = [];

        /** @var list<array{path: string, file: string}> $parsed files that parsed cleanly */
        $parsed = [];

        // Pass 1 — parse, resolve names, collect symbols.
        //
        // Syntax trees are deliberately not kept between the passes. Holding them
        // all costs hundreds of megabytes on a large plugin — enough to exceed
        // PHP's default memory limit and end a batch run on the first big plugin
        // it meets. Re-reading in pass 2 costs some time and makes the footprint
        // of a scan proportional to the largest single file rather than the whole tree.
        foreach ($files as $file) {
            $relative = $this->relativePath($root, $file);
            $ast = $this->parse($file, $relative, $errors);

            if ($ast === null) {
                continue;
            }

            try {
                $collector = new NodeTraverser();
                $collector->addVisitor(new SymbolCollector($symbols, $relative, $callSites));
                $collector->traverse($ast);
            } catch (\Throwable $e) {
                $errors[] = ['file' => $relative, 'message' => $e->getMessage()];
                continue;
            }

            $parsed[] = ['path' => $file, 'file' => $relative];
        }

        $symbols->reconcileInheritedProperties();
        $resolver = new ExpressionResolver($symbols);

        // Pass 2 — detect creates and cleanup removals in one traversal per file.
        $findings = [];
        $removals = [];
        $callbacks = [];
        $uninstallCalls = [];
        $guards = [];
        $blankets = [];
        $requires = [];
        $hasUninstallPhp = false;

        foreach ($parsed as $entry) {
            if (CleanupDiffer::isUninstallFile($entry['file'])) {
                $hasUninstallPhp = true;
            }

            // Re-read rather than held from pass 1, to keep memory bounded. It
            // parsed cleanly then, so a failure here is not recorded twice — it
            // simply leaves nothing to detect in this file.
            $ignored = [];
            $ast = $this->parse($entry['path'], $entry['file'], $ignored);
            if ($ast === null) {
                continue;
            }

            $detectors = [
                new OptionVisitor($entry['file'], $resolver, $callSites),
                new TableVisitor($entry['file'], $resolver, $callSites),
                new CronVisitor($entry['file'], $resolver, $callSites),
                new TransientVisitor($entry['file'], $resolver, $callSites),
                new MetaVisitor($entry['file'], $resolver, $callSites),
                new StructureVisitor($entry['file'], $resolver, $callSites),
                new RewriteVisitor($entry['file'], $resolver, $callSites),
                new FilesystemVisitor($entry['file'], $resolver, $callSites),
                new ScheduleVisitor($entry['file'], $resolver, $callSites),
            ];
            $cleanup = new CleanupVisitor($entry['file'], $resolver, $callSites);

            try {
                $traverser = new NodeTraverser();
                foreach ($detectors as $detector) {
                    $traverser->addVisitor($detector);
                }
                $traverser->addVisitor($cleanup);
                $traverser->traverse($ast);
            } catch (\Throwable $e) {
                // M14 — a visitor bug or unexpected node must never abort the scan.
                $errors[] = ['file' => $entry['file'], 'message' => $e->getMessage()];
                continue;
            }

            foreach ($detectors as $detector) {
                /** @var AbstractDetectionVisitor $detector */
                foreach ($detector->findings() as $finding) {
                    // A write keyed on a wrapper's parameter is replaced by the
                    // keys its callers actually pass (§0.7). Everything else
                    // comes through untouched.
                    foreach (WrapperExpander::expand($finding, $detector->expansionsAt($finding->line)) as $expanded) {
                        $findings[] = $expanded;
                    }
                }
            }
            array_push($removals, ...$cleanup->removals());
            array_push($callbacks, ...$cleanup->uninstallCallbacks());
            array_push($uninstallCalls, ...$cleanup->uninstallCalls());
            array_push($guards, ...$cleanup->guards());
            array_push($blankets, ...$cleanup->blanketRemovals());
            array_push($requires, ...$cleanup->requires());
        }

        // Top-level code in a file uninstall.php requires runs on uninstall too,
        // so its removals credit cleanup the same way uninstall.php's own do.
        $uninstallFiles = CleanupDiffer::reachableUninstallFiles($requires);

        $findings = CleanupDiffer::apply($findings, $removals, $callbacks, $uninstallCalls, $blankets, $uninstallFiles);
        $condition = CleanupDiffer::condition($guards, $callbacks, $uninstallCalls, $removals, $uninstallFiles);

        return [
            'files' => $files,
            'findings' => $findings,
            'errors' => $errors,
            'cleanup' => [
                'has_uninstall_php' => $hasUninstallPhp,
                'has_uninstall_hook' => $callbacks !== [],
                'conditional' => $condition !== null,
                'condition_option' => $condition['option'] ?? null,
                'condition_default' => $condition['default'] ?? null,
            ],
        ];
    }

    /**
     * Read and parse one file, with names resolved to their fully-qualified form.
     * Returns null when the file cannot be read or parsed, recording why — a
     * malformed file never ends a scan (M14).
     *
     * @param list<array{file: string, message: string}>|null $errors
     * @return Node[]|null
     */
    private function parse(string $path, string $relative, ?array &$errors): ?array
    {
        $code = @file_get_contents($path);
        if ($code === false) {
            $errors[] = ['file' => $relative, 'message' => 'unreadable'];

            return null;
        }

        try {
            $ast = $this->parser->parse($code);

            return $ast === null ? null : $this->resolveNames($ast);
        } catch (\Throwable $e) {
            $errors[] = ['file' => $relative, 'message' => $e->getMessage()];

            return null;
        }
    }

    /**
     * @param Node[] $ast
     * @return Node[]
     */
    private function resolveNames(array $ast): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => false]));

        return $traverser->traverse($ast);
    }

    private function relativePath(string $root, string $file): string
    {
        $root = rtrim($root, "/\\");

        if (is_file($root)) {
            return basename($file);
        }

        return str_replace('\\', '/', ltrim(substr($file, strlen($root)), "/\\"));
    }
}
