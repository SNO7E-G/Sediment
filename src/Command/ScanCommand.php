<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sediment scan <path>` — walk a plugin directory and report what it leaves
 * behind, grouped by artifact type with a per-finding confidence level and an
 * honest resolution rate.
 */
#[AsCommand(
    name: 'scan',
    description: 'Scan a plugin directory and report what it leaves behind.',
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
        /** @var list<Finding> $findings */
        $findings = $result['findings'];

        $io->title('Sediment');
        $io->text(sprintf('Scanned <info>%d</info> PHP file(s) under <comment>%s</comment>.', count($result['files']), $path));
        $io->newLine();

        if ($findings === []) {
            $io->success('No tracked artifact writes detected.');
        } else {
            $this->renderGroup($io, 'Options', $this->ofType($findings, 'option'), true);
            $this->renderGroup($io, 'Tables', $this->ofType($findings, 'table'), false);
            $this->renderGroup($io, 'Cron events', $this->ofType($findings, 'cron'), false);
            $this->renderGroup($io, 'Transients', $this->ofType($findings, 'transient'), false);
            $this->renderCoverage($io, $findings);
        }

        if ($result['errors'] !== []) {
            $io->warning(sprintf(
                '%d file(s) could not be parsed and were skipped (degraded, never fatal).',
                count($result['errors'])
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function ofType(array $findings, string $type): array
    {
        return array_values(array_filter($findings, static fn (Finding $f): bool => $f->type === $type));
    }

    /**
     * @param list<Finding> $findings
     */
    private function renderGroup(SymfonyStyle $io, string $title, array $findings, bool $showAutoload): void
    {
        if ($findings === []) {
            return;
        }

        $io->section(sprintf('%s (%d)', $title, count($findings)));

        $headers = ['function', 'key', 'confidence'];
        if ($showAutoload) {
            $headers[] = 'autoload';
        }
        $headers[] = 'source';

        $rows = [];
        foreach ($findings as $f) {
            $row = [
                $f->function,
                $f->key ?? '<fg=gray>— (' . ($f->expression ?? 'unresolved') . ')</>',
                $this->badge($f->confidence),
            ];
            if ($showAutoload) {
                $row[] = $this->autoloadBadge($f->autoload);
            }
            $row[] = $f->file . ':' . $f->line;
            $rows[] = $row;
        }

        $io->table($headers, $rows);
    }

    /**
     * @param list<Finding> $findings
     */
    private function renderCoverage(SymfonyStyle $io, array $findings): void
    {
        $total = count($findings);
        $resolved = count(array_filter(
            $findings,
            static fn (Finding $f): bool => $f->confidence === Finding::CONFIDENCE_VERIFIED
                || $f->confidence === Finding::CONFIDENCE_RESOLVED,
        ));

        $rate = $total > 0 ? $resolved / $total : 1.0;
        $io->writeln(sprintf(
            ' Resolution rate: <info>%.1f%%</info> (%d of %d write calls resolved to a key).',
            $rate * 100,
            $resolved,
            $total
        ));
        $io->newLine();
    }

    private function badge(string $confidence): string
    {
        return match ($confidence) {
            Finding::CONFIDENCE_VERIFIED => '<fg=green>verified</>',
            Finding::CONFIDENCE_RESOLVED => '<fg=cyan>resolved</>',
            Finding::CONFIDENCE_PATTERN  => '<fg=yellow>pattern</>',
            default                      => '<fg=red>dynamic</>',
        };
    }

    private function autoloadBadge(?string $autoload): string
    {
        return match ($autoload) {
            'yes'     => '<fg=red>yes</>',
            'no'      => '<fg=green>no</>',
            'unknown' => '<fg=gray>?</>',
            default   => '',
        };
    }
}
