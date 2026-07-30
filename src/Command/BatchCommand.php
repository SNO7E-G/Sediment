<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sediment batch <dir>` — scan every plugin directory under `<dir>`, writing a
 * manifest for each plus a summary.
 *
 * One plugin at a time answers "what does this leave behind"; a batch answers
 * "what does this ecosystem leave behind", which is the question the Index
 * exists to answer. A plugin that fails to scan is recorded and skipped — one
 * bad plugin must never sink a run of thousands.
 */
#[AsCommand(
    name: 'batch',
    description: 'Scan every plugin in a directory, writing a manifest for each.',
)]
final class BatchCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Directory containing one subdirectory per plugin');
        $this->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Where to write manifests', 'sediment-manifests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = rtrim((string) $input->getArgument('directory'), "/\\");
        $out = rtrim((string) $input->getOption('out'), "/\\");

        if (!is_dir($directory)) {
            $io->error("Directory not found: {$directory}");

            return Command::FAILURE;
        }

        $plugins = array_values(array_filter((array) glob($directory . '/*'), 'is_dir'));
        if ($plugins === []) {
            $io->error("No plugin directories found in {$directory}");

            return Command::FAILURE;
        }

        if (!is_dir($out) && !@mkdir($out, 0777, true) && !is_dir($out)) {
            $io->error("Could not create output directory: {$out}");

            return Command::FAILURE;
        }

        $io->title('Sediment batch');
        $io->progressStart(count($plugins));

        $scanner = new Scanner();
        $grader = new Grader();
        $grades = [];
        $failed = [];
        $resolutionTotals = ['resolved' => 0, 'total' => 0];

        foreach ($plugins as $path) {
            $slug = basename($path);

            try {
                $scan = $scanner->scan($path);
                $grade = $grader->grade($scan['findings'], $scan['cleanup']);
                $manifest = Manifest::build($scan, $grade, $path, gmdate('Y-m-d\TH:i:s\Z'));

                file_put_contents(
                    $out . '/' . $slug . '.json',
                    (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );

                $grades[$grade->letter] = ($grades[$grade->letter] ?? 0) + 1;
                $resolutionTotals['resolved'] += $manifest['coverage']['verified'] + $manifest['coverage']['resolved'];
                $resolutionTotals['total'] += $manifest['coverage']['write_calls_found'];
            } catch (\Throwable $e) {
                // One unscannable plugin must not end a run of thousands.
                $failed[$slug] = $e->getMessage();
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        ksort($grades);
        $scanned = count($plugins) - count($failed);

        $io->writeln(sprintf(' Scanned <info>%d</info> plugin(s) into <comment>%s/</comment>.', $scanned, $out));
        $io->newLine();

        if ($grades !== []) {
            $io->table(
                ['grade', 'plugins', 'share'],
                array_map(
                    static fn (string $letter, int $count): array => [
                        $letter,
                        $count,
                        sprintf('%.1f%%', $scanned > 0 ? $count / $scanned * 100 : 0),
                    ],
                    array_keys($grades),
                    array_values($grades),
                ),
            );
        }

        $io->writeln(sprintf(
            ' Overall resolution rate: <info>%.1f%%</info> across %d write call(s).',
            $resolutionTotals['total'] > 0 ? $resolutionTotals['resolved'] / $resolutionTotals['total'] * 100 : 100,
            $resolutionTotals['total'],
        ));

        if ($failed !== []) {
            $io->newLine();
            $io->warning(sprintf('%d plugin(s) could not be scanned: %s', count($failed), implode(', ', array_keys($failed))));
        }

        return Command::SUCCESS;
    }
}
