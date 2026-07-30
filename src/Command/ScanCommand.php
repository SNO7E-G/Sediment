<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Finding;
use Sediment\Analyzer\Scanner;
use Sediment\Manifest\Grader;
use Sediment\Manifest\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit a JSON manifest instead of the terminal report');
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

        if ($input->getOption('json')) {
            $manifest = Manifest::build(
                $result,
                (new Grader())->grade($result['findings'], $result['cleanup']),
                $path,
                gmdate('Y-m-d\TH:i:s\Z'),
            );

            $output->writeln(Manifest::toJson($manifest), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

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
            $this->renderGroup($io, 'Post meta', $this->ofType($findings, 'post_meta'), false);
            $this->renderGroup($io, 'User meta', $this->ofType($findings, 'user_meta'), false);
            $this->renderGroup($io, 'Term meta', $this->ofType($findings, 'term_meta'), false);
            $this->renderGroup($io, 'Comment meta', $this->ofType($findings, 'comment_meta'), false);
            $this->renderGroup($io, 'Roles', $this->ofType($findings, 'role'), false);
            $this->renderGroup($io, 'Capabilities', $this->ofType($findings, 'capability'), false);
            $this->renderGroup($io, 'Post types', $this->ofType($findings, 'post_type'), false);
            $this->renderGroup($io, 'Taxonomies', $this->ofType($findings, 'taxonomy'), false);
            $this->renderGroup($io, 'Directories', $this->ofType($findings, 'directory'), false);
            $this->renderGroup($io, 'Rewrite rules', $this->ofType($findings, 'rewrite_rule'), false);
            $this->renderGroup($io, 'Action Scheduler jobs', $this->ofType($findings, 'action'), false);
            $this->renderCoverage($io, $findings);
            $this->renderCleanup($io, $findings, $result['cleanup']);
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
        $headers[] = 'cleaned';
        $headers[] = 'source';

        $rows = [];
        foreach ($findings as $f) {
            // Scanned source (key/expression) may contain '<...>'; escape it so it
            // can never be read as console formatting markup.
            $keyCell = $f->key !== null
                ? OutputFormatter::escape($f->key)
                : '<fg=gray>— (' . OutputFormatter::escape($f->expression ?? 'unresolved') . ')</>';

            $row = [
                $f->function,
                $keyCell,
                $this->badge($f->confidence),
            ];
            if ($showAutoload) {
                $row[] = $this->autoloadBadge($f->autoload);
            }
            $row[] = $this->cleanedBadge($f->cleaned);
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
        $resolved = count(array_filter($findings, static fn (Finding $f): bool => $f->isConfident()));

        $rate = $total > 0 ? $resolved / $total : 1.0;
        $io->writeln(sprintf(
            ' Resolution rate: <info>%.1f%%</info> (%d of %d write calls resolved to a key).',
            $rate * 100,
            $resolved,
            $total
        ));
        $io->newLine();
    }

    /**
     * @param list<Finding> $findings
     * @param array{has_uninstall_php: bool, has_uninstall_hook: bool, conditional?: bool, condition_option?: string|null, condition_default?: bool|string|null} $cleanup
     */
    private function renderCleanup(SymfonyStyle $io, array $findings, array $cleanup): void
    {
        $total = count($findings);
        $cleaned = count(array_filter($findings, static fn (Finding $f): bool => $f->cleaned === true));

        $path = [];
        if ($cleanup['has_uninstall_php']) {
            $path[] = 'uninstall.php';
        }
        if ($cleanup['has_uninstall_hook']) {
            $path[] = 'register_uninstall_hook';
        }
        $pathText = $path === [] ? '<fg=red>none</>' : implode(' + ', $path);

        $io->writeln(sprintf(
            ' Cleanup path: %s — <info>%d</info> of %d detected artifacts removed on uninstall.',
            $pathText,
            $cleaned,
            $total
        ));

        if (($cleanup['conditional'] ?? false) === true) {
            // The gate's polarity is not inspected, so say which setting decides
            // rather than which way it must be set.
            $io->writeln(sprintf(
                ' <comment>Conditional:</comment> cleanup only runs when the %s setting allows it.',
                ($cleanup['condition_option'] ?? null) !== null
                    ? '"' . OutputFormatter::escape((string) $cleanup['condition_option']) . '"'
                    : 'stored',
            ));
        }

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

    private function cleanedBadge(?bool $cleaned): string
    {
        return match ($cleaned) {
            true  => '<fg=green>yes</>',
            false => '<fg=red>no</>',
            default => '',
        };
    }
}
