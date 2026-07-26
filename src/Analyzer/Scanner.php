<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Error;
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
        $errors = [];

        /** @var list<array{file: string, ast: Node[]}> $parsed */
        $parsed = [];

        // Pass 1 — parse, resolve names, collect symbols.
        foreach ($files as $file) {
            $code = @file_get_contents($file);
            if ($code === false) {
                $errors[] = ['file' => $file, 'message' => 'unreadable'];
                continue;
            }

            try {
                $ast = $this->parser->parse($code);
            } catch (Error $e) {
                $errors[] = ['file' => $file, 'message' => $e->getMessage()];
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $relative = $this->relativePath($root, $file);

            try {
                $ast = $this->resolveNames($ast);

                $collector = new NodeTraverser();
                $collector->addVisitor(new SymbolCollector($symbols, $relative));
                $collector->traverse($ast);
            } catch (\Throwable $e) {
                $errors[] = ['file' => $relative, 'message' => $e->getMessage()];
                continue;
            }

            $parsed[] = ['file' => $relative, 'ast' => $ast];
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
        $hasUninstallPhp = false;

        foreach ($parsed as $entry) {
            if (CleanupDiffer::isUninstallFile($entry['file'])) {
                $hasUninstallPhp = true;
            }

            $detectors = [
                new OptionVisitor($entry['file'], $resolver),
                new TableVisitor($entry['file'], $resolver),
                new CronVisitor($entry['file'], $resolver),
                new TransientVisitor($entry['file'], $resolver),
                new MetaVisitor($entry['file'], $resolver),
                new StructureVisitor($entry['file'], $resolver),
                new RewriteVisitor($entry['file'], $resolver),
                new FilesystemVisitor($entry['file'], $resolver),
                new ScheduleVisitor($entry['file'], $resolver),
            ];
            $cleanup = new CleanupVisitor($entry['file'], $resolver);

            try {
                $traverser = new NodeTraverser();
                foreach ($detectors as $detector) {
                    $traverser->addVisitor($detector);
                }
                $traverser->addVisitor($cleanup);
                $traverser->traverse($entry['ast']);
            } catch (\Throwable $e) {
                // M14 — a visitor bug or unexpected node must never abort the scan.
                $errors[] = ['file' => $entry['file'], 'message' => $e->getMessage()];
                continue;
            }

            foreach ($detectors as $detector) {
                /** @var AbstractDetectionVisitor $detector */
                foreach ($detector->findings() as $finding) {
                    $findings[] = $finding;
                }
            }
            array_push($removals, ...$cleanup->removals());
            array_push($callbacks, ...$cleanup->uninstallCallbacks());
            array_push($uninstallCalls, ...$cleanup->uninstallCalls());
            array_push($guards, ...$cleanup->guards());
            array_push($blankets, ...$cleanup->blanketRemovals());
        }

        $findings = CleanupDiffer::apply($findings, $removals, $callbacks, $uninstallCalls, $blankets);
        $condition = CleanupDiffer::condition($guards, $callbacks, $uninstallCalls, $removals);

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
