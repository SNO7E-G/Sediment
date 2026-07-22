<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Sediment\Analyzer\Visitors\OptionVisitor;

/**
 * Orchestrates the scan in two passes (§12):
 *  1. Parse every file and harvest literal symbols into a {@see SymbolTable}, so
 *     a constant defined in one file resolves a key written in another.
 *  2. Re-walk each file with the detection visitors, resolving keys against the
 *     symbol table.
 *
 * Parse errors never fatal (M14): a malformed file is recorded and skipped.
 */
final class Scanner
{
    /**
     * Detection visitors always run. Additional visitors are picked up when
     * present, so Cron/Transient detection activates as soon as those classes
     * exist without touching this file.
     *
     * @var list<class-string>
     */
    private const OPTIONAL_VISITORS = [
        'Sediment\\Analyzer\\Visitors\\CronVisitor',
        'Sediment\\Analyzer\\Visitors\\TransientVisitor',
    ];

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
     *     errors: list<array{file: string, message: string}>
     * }
     */
    public function scan(string $root): array
    {
        $files = $this->walker->walk($root);

        $symbols = new SymbolTable();
        $errors = [];

        /** @var list<array{file: string, ast: Node[]}> $parsed */
        $parsed = [];

        // Pass 1 — parse and collect symbols.
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

            $collector = new NodeTraverser();
            $collector->addVisitor(new SymbolCollector($symbols));
            $collector->traverse($ast);

            $parsed[] = ['file' => $relative, 'ast' => $ast];
        }

        // Pass 2 — detect, resolving against the now-complete symbol table.
        $symbols->reconcileInheritedProperties();
        $resolver = new ExpressionResolver($symbols);
        $findings = [];

        foreach ($parsed as $entry) {
            $visitors = [new OptionVisitor($entry['file'], $resolver)];

            foreach (self::OPTIONAL_VISITORS as $class) {
                if (class_exists($class)) {
                    /** @var Visitors\AbstractDetectionVisitor $visitor */
                    $visitor = new $class($entry['file'], $resolver);
                    $visitors[] = $visitor;
                }
            }

            try {
                $traverser = new NodeTraverser();
                foreach ($visitors as $visitor) {
                    $traverser->addVisitor($visitor);
                }
                $traverser->traverse($entry['ast']);

                foreach ($visitors as $visitor) {
                    foreach ($visitor->findings() as $finding) {
                        $findings[] = $finding;
                    }
                }
            } catch (\Throwable $e) {
                // M14 — an unexpected node or a visitor bug must never abort the
                // whole scan; record it and move on.
                $errors[] = ['file' => $entry['file'], 'message' => $e->getMessage()];
            }
        }

        return [
            'files' => $files,
            'findings' => $findings,
            'errors' => $errors,
        ];
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
