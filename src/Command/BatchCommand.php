<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Manifest\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * `sediment batch <dir>` — scan every plugin directory under `<dir>`, writing a
 * manifest for each plus a summary.
 *
 * One plugin at a time answers "what does this leave behind"; a batch answers
 * "what does this ecosystem leave behind", which is the question the Index
 * exists to answer.
 *
 * Each plugin is scanned in its own child process with a wall-clock timeout and
 * a memory cap. In-process, one pathological plugin — a parser blow-up, an
 * infinite loop in a visitor, a file that exhausts memory — takes the whole run
 * of thousands down with it. In a child, it costs one manifest and a line in
 * the report.
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
        $this->addOption('resume', null, InputOption::VALUE_NONE, 'Skip plugins that already have a manifest in the output directory');
        $this->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write a JSON summary of the run, including every failure');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Wall-clock seconds one plugin may take before it is recorded as failed', '300');
        $this->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'PHP memory limit for one plugin scan (e.g. 512M)', '512M');
        $this->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'How many plugins to scan concurrently', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = rtrim((string) $input->getArgument('directory'), "/\\");
        $out = rtrim((string) $input->getOption('out'), "/\\");
        $timeout = max(1, (int) $input->getOption('timeout'));
        $memoryLimit = (string) $input->getOption('memory-limit');

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

        $grades = [];
        $failed = [];
        $resolutionTotals = ['resolved' => 0, 'total' => 0];

        $resume = (bool) $input->getOption('resume');
        $skipped = 0;

        // A bounded pool of child scans. Each plugin is one independent child
        // and the tallies do not depend on completion order, so the report is
        // identical whatever the interleaving — parallelism only buys time.
        $jobs = max(1, (int) $input->getOption('jobs'));
        $queue = $plugins;
        /** @var array<string, Process> $running */
        $running = [];

        while ($queue !== [] || $running !== []) {
            while (count($running) < $jobs && $queue !== []) {
                $path = (string) array_shift($queue);
                $slug = basename($path);

                // A run over thousands of plugins will be interrupted — a
                // timeout, a full disk, a machine going away. Resuming from the
                // manifests already written turns that from "start again" into
                // "carry on".
                if ($resume && is_file($out . '/' . $slug . '.json')) {
                    $skipped++;
                    $io->progressAdvance();

                    continue;
                }

                $process = $this->startChild($path, $timeout, $memoryLimit, $failed, $slug);
                if ($process === null) {
                    $io->progressAdvance();

                    continue;
                }

                $running[$slug] = $process;
            }

            foreach ($running as $slug => $process) {
                // isRunning() first: it refreshes the process status, where
                // checkTimeout() trusts the cached one. The other way round, a
                // child finishing right at the timeout boundary reads as timed
                // out and its perfectly good manifest is thrown away.
                if ($process->isRunning()) {
                    try {
                        // With start() instead of run(), the wall clock is only
                        // enforced when someone asks — this poll is that someone.
                        $process->checkTimeout();
                    } catch (ProcessTimedOutException) {
                        $failed[$slug] = ['reason' => 'timeout', 'detail' => sprintf('exceeded the %ds wall-clock timeout', $timeout)];
                        unset($running[$slug]);
                        $io->progressAdvance();
                    }

                    continue;
                }

                unset($running[$slug]);

                $manifest = $this->harvest($process, $failed, $slug);
                if ($manifest !== null) {
                    // Re-encoded rather than written as the child sent it, so
                    // the document on disk cannot pick up platform line endings
                    // on the way through a pipe.
                    file_put_contents($out . '/' . $slug . '.json', Manifest::toJson($manifest));

                    $grades[$manifest['grade']] = ($grades[$manifest['grade']] ?? 0) + 1;
                    $resolutionTotals['resolved'] += $manifest['coverage']['verified'] + $manifest['coverage']['resolved'];
                    $resolutionTotals['total'] += $manifest['coverage']['write_calls_found'];
                }

                $io->progressAdvance();
            }

            if ($running !== []) {
                usleep(50000);
            }
        }

        $io->progressFinish();

        ksort($grades);
        $scanned = count($plugins) - count($failed) - $skipped;

        if ($skipped > 0) {
            $io->writeln(sprintf(' Resumed: skipped <info>%d</info> plugin(s) already scanned.', $skipped));
        }

        $io->writeln(sprintf(' Scanned <info>%d</info> plugin(s) into <comment>%s/</comment>.', $scanned, OutputFormatter::escape($out)));
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

        $report = $input->getOption('report');
        if (is_string($report) && $report !== '') {
            // A run of thousands cannot be debugged from a terminal warning that
            // scrolled past, so the failures are written down with their reasons.
            file_put_contents($report, (string) json_encode([
                'scanned' => $scanned,
                'skipped' => $skipped,
                'grades' => $grades,
                'resolution' => [
                    'resolved' => $resolutionTotals['resolved'],
                    'write_calls' => $resolutionTotals['total'],
                ],
                'failed' => $failed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $io->writeln(sprintf(' Report written to <comment>%s</comment>.', OutputFormatter::escape($report)));
        }

        if ($failed !== []) {
            $io->newLine();
            $io->warning(sprintf('%d plugin(s) could not be scanned: %s', count($failed), implode(', ', array_keys($failed))));
        }

        return Command::SUCCESS;
    }

    /**
     * Start one plugin's scan in a child process, or record why it could not
     * start.
     *
     * @param array<string, array{reason: string, detail: string}> $failed
     */
    private function startChild(string $path, int $timeout, string $memoryLimit, array &$failed, string $slug): ?Process
    {
        // Inside a PHAR the entry point is the archive itself; from source it is
        // the repository's own binary. Either way the child runs the exact code
        // the parent is running.
        $binary = \Phar::running(false) !== '' ? \Phar::running(false) : dirname(__DIR__, 2) . '/bin/sediment';

        $process = new Process([\PHP_BINARY, '-d', 'memory_limit=' . $memoryLimit, $binary, 'scan', $path, '--json']);
        $process->setTimeout((float) $timeout);

        try {
            $process->start();
        } catch (\Throwable $e) {
            $failed[$slug] = ['reason' => 'error', 'detail' => $e->getMessage()];

            return null;
        }

        return $process;
    }

    /**
     * Read a finished child's result, or record why there is none.
     *
     * @param array<string, array{reason: string, detail: string}> $failed
     * @return array<string, mixed>|null the decoded manifest, or null on failure
     */
    private function harvest(Process $process, array &$failed, string $slug): ?array
    {
        if (!$process->isSuccessful()) {
            $detail = trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : trim($process->getOutput());
            $failed[$slug] = [
                'reason' => 'error',
                'detail' => sprintf('exit code %d: %s', (int) $process->getExitCode(), mb_substr($detail, 0, 500)),
            ];

            return null;
        }

        $manifest = json_decode($process->getOutput(), true);
        if (!is_array($manifest) || !isset($manifest['grade'], $manifest['coverage'])) {
            $failed[$slug] = ['reason' => 'error', 'detail' => 'the scan produced no readable manifest'];

            return null;
        }

        return $manifest;
    }
}
