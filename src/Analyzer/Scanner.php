<?php

declare(strict_types=1);

namespace Sediment\Analyzer;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Sediment\Analyzer\Visitors\OptionVisitor;

/**
 * Orchestrates the scan: walk files, parse each to an AST, run the detection
 * visitors, and collect findings.
 *
 * Spike scope covers options only. Tables, cron, transients, the symbol-table
 * pass, and cleanup diffing follow (§6, weeks 1-2). Parse errors never fatal
 * (M14): a malformed file is recorded and skipped, not thrown.
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
     *     options: list<Finding>,
     *     errors: list<array{file: string, message: string}>
     * }
     */
    public function scan(string $root): array
    {
        $files = $this->walker->walk($root);

        $options = [];
        $errors = [];

        foreach ($files as $file) {
            $code = @file_get_contents($file);
            if ($code === false) {
                $errors[] = ['file' => $file, 'message' => 'unreadable'];
                continue;
            }

            try {
                $ast = $this->parser->parse($code);
            } catch (Error $e) {
                // M14 — degrade, never crash on hostile or malformed PHP.
                $errors[] = ['file' => $file, 'message' => $e->getMessage()];
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $optionVisitor = new OptionVisitor($this->relativePath($root, $file));

            $traverser = new NodeTraverser();
            $traverser->addVisitor($optionVisitor);
            $traverser->traverse($ast);

            foreach ($optionVisitor->findings() as $finding) {
                $options[] = $finding;
            }
        }

        return [
            'files' => $files,
            'options' => $options,
            'errors' => $errors,
        ];
    }

    private function relativePath(string $root, string $file): string
    {
        $root = rtrim($root, "/\\");

        if (is_file($root)) {
            return basename($file);
        }

        $relative = ltrim(substr($file, strlen($root)), "/\\");

        return str_replace('\\', '/', $relative);
    }
}
