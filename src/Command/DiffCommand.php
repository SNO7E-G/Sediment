<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;
use Sediment\Manifest\ManifestDiff;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sediment diff <baseline.json> <path>` — show what a release changed about a
 * plugin's database footprint, and exit non-zero when it got worse.
 *
 * Commit the manifest, and this turns "we accidentally added an autoloaded
 * option" into a failing build instead of a discovery years later.
 */
#[AsCommand(
    name: 'diff',
    description: 'Compare a plugin against a saved manifest and fail on a worse footprint.',
)]
final class DiffCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('baseline', InputArgument::REQUIRED, 'Path to a manifest saved earlier (sediment scan --json)');
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the plugin directory to compare against it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $baselinePath = (string) $input->getArgument('baseline');
        $path = (string) $input->getArgument('path');

        if (!is_file($baselinePath)) {
            $io->error("Baseline manifest not found: {$baselinePath}");

            return Command::FAILURE;
        }

        $before = json_decode((string) @file_get_contents($baselinePath), true);
        if (!is_array($before)) {
            $io->error("Baseline manifest is not valid JSON: {$baselinePath}");

            return Command::FAILURE;
        }

        if (!file_exists($path)) {
            $io->error("Path not found: {$path}");

            return Command::FAILURE;
        }

        $scan = (new Scanner())->scan($path);
        $after = Manifest::build($scan, (new Grader())->grade($scan['findings'], $scan['cleanup']), $path, gmdate('Y-m-d\TH:i:s\Z'));
        $diff = ManifestDiff::between($before, $after);

        $io->title('Sediment diff');
        $io->writeln(sprintf(' Grade: %s → %s', $diff['grade_before'], $diff['grade_after']));
        $io->newLine();

        $this->listGroup($io, 'New artifacts', $diff['added'], 'yellow');
        $this->listGroup($io, 'No longer cleaned up', $diff['no_longer_cleaned'], 'red');
        $this->listGroup($io, 'Now cleaned up', $diff['newly_cleaned'], 'green');
        $this->listGroup($io, 'No longer created', $diff['removed'], 'green');

        if ($diff['regressed']) {
            $io->error('The database footprint got worse.');

            return Command::FAILURE;
        }

        $io->success('No footprint regression.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $items
     */
    private function listGroup(SymfonyStyle $io, string $title, array $items, string $colour): void
    {
        if ($items === []) {
            return;
        }

        $io->writeln(sprintf(' <fg=%s>%s (%d)</>', $colour, $title, count($items)));
        foreach ($items as $item) {
            $io->writeln('   ' . OutputFormatter::escape($item));
        }
        $io->newLine();
    }
}
