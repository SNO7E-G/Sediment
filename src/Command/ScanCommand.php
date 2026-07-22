<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Scanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sediment scan <path>` — walk a plugin directory and report what it leaves
 * behind. Spike scope: option writes only, with confidence levels.
 */
#[AsCommand(
    name: 'scan',
    description: 'Scan a plugin directory and report what it leaves behind (spike: options only).',
)]
final class ScanCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the plugin directory (or file) to scan');
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

        $io->title('Sediment scan (spike)');
        $io->text(sprintf('Scanned <info>%d</info> PHP file(s) under <comment>%s</comment>.', count($result['files']), $path));
        $io->newLine();

        $options = $result['options'];
        if ($options === []) {
            $io->success('No option writes detected.');
        } else {
            $rows = [];
            foreach ($options as $finding) {
                $rows[] = [
                    $finding->function,
                    $finding->key ?? '<fg=gray>— (unresolved)</>',
                    $this->badge($finding->confidence),
                    $finding->file . ':' . $finding->line,
                ];
            }

            $io->section(sprintf('Options (%d)', count($options)));
            $io->table(['function', 'key', 'confidence', 'source'], $rows);
        }

        if ($result['errors'] !== []) {
            $io->warning(sprintf(
                '%d file(s) could not be parsed and were skipped (degraded, never fatal — M14).',
                count($result['errors'])
            ));
        }

        return Command::SUCCESS;
    }

    private function badge(string $confidence): string
    {
        return match ($confidence) {
            'verified' => '<fg=green>verified</>',
            'resolved' => '<fg=cyan>resolved</>',
            'pattern'  => '<fg=yellow>pattern</>',
            default    => '<fg=red>dynamic</>',
        };
    }
}
