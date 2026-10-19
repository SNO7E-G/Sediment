<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sediment check <path> --fail-on=<grade>` — exit non-zero when a plugin grades
 * worse than the threshold, so a plugin author can gate their own CI on the
 * database footprint the same way they gate on tests.
 */
#[AsCommand(
    name: 'check',
    description: 'Fail (exit 1) when a plugin grades worse than a threshold.',
)]
final class CheckCommand extends Command
{
    /** Best to worst; the index is the comparison. */
    private const ORDER = ['A', 'B', 'C', 'D', 'F'];

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the plugin directory to check');
        $this->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Worst grade that still passes (A, B, C, D or F)', 'D');
        $this->addOption('min-coverage', null, InputOption::VALUE_REQUIRED, 'Also fail when fewer than this percentage of write calls resolve to a key (0-100)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('path');
        $threshold = strtoupper((string) $input->getOption('fail-on'));

        if (!in_array($threshold, self::ORDER, true)) {
            $io->error(sprintf('Invalid --fail-on value "%s". Use one of: %s.', $threshold, implode(', ', self::ORDER)));

            return Command::INVALID;
        }

        $minCoverage = $input->getOption('min-coverage');
        if ($minCoverage !== null && (!is_string($minCoverage) || !is_numeric($minCoverage) || $minCoverage < 0 || $minCoverage > 100)) {
            $io->error(sprintf('Invalid --min-coverage value "%s". Use a percentage from 0 to 100.', is_string($minCoverage) ? $minCoverage : '?'));

            return Command::INVALID;
        }

        if (!file_exists($path)) {
            $io->error("Path not found: {$path}");

            return Command::FAILURE;
        }

        $result = (new Scanner())->scan($path);
        $grade = (new Grader())->grade($result['findings'], $result['cleanup']);

        // A grade only covers the writes Sediment could name. A plugin whose
        // keys are all built at runtime has nothing confident to grade and
        // comes out A — so a gate that cares can demand coverage too.
        if ($minCoverage !== null) {
            $total = count($result['findings']);
            $resolved = count(array_filter($result['findings'], static fn ($f): bool => $f->isConfident()));
            $rate = $total > 0 ? $resolved / $total * 100 : 100.0;

            if ($rate < (float) $minCoverage) {
                $io->error(sprintf('Only %.1f%% of write calls resolved (%d of %d), below --min-coverage=%s.', $rate, $resolved, $total, (string) $minCoverage));

                return Command::FAILURE;
            }
        }

        $worseThanThreshold = array_search($grade->letter, self::ORDER, true) > array_search($threshold, self::ORDER, true);

        if ($worseThanThreshold) {
            $io->error(sprintf('%s (%d/100) is worse than the --fail-on=%s threshold.', $grade->letter, $grade->score, $threshold));
            $io->writeln(' ' . $grade->summary);

            return Command::FAILURE;
        }

        $io->success(sprintf('%s (%d/100) meets the --fail-on=%s threshold.', $grade->letter, $grade->score, $threshold));

        return Command::SUCCESS;
    }
}
