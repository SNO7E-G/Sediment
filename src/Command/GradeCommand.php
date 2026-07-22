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
