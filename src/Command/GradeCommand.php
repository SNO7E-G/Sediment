<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grade;
use Sediment\Manifest\Grader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sediment grade <path>` — assign a defensible A–F grade based on what the
 * plugin leaves behind after uninstall.
 */
#[AsCommand(
    name: 'grade',
    description: 'Grade a plugin A–F by what it leaves behind on uninstall.',
)]
final class GradeCommand extends Command
{
    /**
     * Below this share of resolved write calls, the grade is reported as a floor
     * rather than a verdict. Set where the corpus splits: the plugins above it
     * are ones Sediment can read almost completely.
     */
    private const CONFIDENT_COVERAGE = 0.90;

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the plugin directory to grade');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('path');

        if (!file_exists($path)) {
            $io->error("Path not found: {$path}");

            return Command::FAILURE;
        }

        $result = (new Scanner())->scan($path);
        $grade = (new Grader())->grade($result['findings'], $result['cleanup']);

        $io->title('Sediment grade');
        $io->writeln(sprintf(
            ' <fg=%s;options=bold>%s</>   score %d/100',
            $this->color($grade->letter),
            $grade->letter,
            $grade->score,
        ));
        $io->newLine();
        $io->writeln(' ' . $grade->summary);
        $io->writeln(sprintf(
            ' Removed %d of %d confidently-detected artifact(s) on uninstall.',
            $grade->cleaned,
            $grade->cleaned + $grade->leftBehind,
        ));

        $unresolved = count(array_filter($result['findings'], static fn (Finding $f): bool => !$f->isConfident()));
        if ($unresolved > 0) {
            $io->writeln(sprintf(
                ' <comment>%d finding(s) could not be fully resolved and were excluded from the grade.</comment>',
                $unresolved,
            ));
        }

        // A grade is only as good as the share of writes behind it. Yoast SEO
        // resolves 64% of its write calls, so its D is a floor rather than a
        // verdict — the third of its writes nobody can read statically can only
        // make the real footprint larger. Saying so beside the letter stops the
        // grade being read as more certain than it is.
        $total = count($result['findings']);
        $coverage = $total > 0 ? ($total - $unresolved) / $total : 1.0;

        if ($coverage < self::CONFIDENT_COVERAGE && $total > 0) {
            $io->newLine();
            $io->writeln(sprintf(
                ' <comment>Coverage: %.0f%% of write calls resolved — treat this grade as a floor.</comment>',
                $coverage * 100,
            ));
            $io->writeln(' <comment>What could not be read can only add to the footprint, never subtract from it.</comment>');
        }

        $io->newLine();
        $io->writeln(' Rubric: docs/grading.md');

        return Command::SUCCESS;
    }

    private function color(string $letter): string
    {
        return match ($letter) {
            'A' => 'green',
            'B' => 'cyan',
            'C' => 'yellow',
            default => 'red',
        };
    }
}
