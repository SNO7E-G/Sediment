<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Source\WordPressOrgClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sediment fetch <slug>` — download a plugin from wordpress.org into a local
 * cache so it can be scanned.
 *
 * Pass `--version` to pin. The golden corpus depends on this: a corpus pinned to
 * whatever happens to be current rewrites its own expectations whenever an
 * author ships, which is the opposite of a regression net.
 */
#[AsCommand(
    name: 'fetch',
    description: 'Download a plugin from wordpress.org into a local cache.',
)]
final class FetchCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED, 'The wordpress.org plugin slug, e.g. contact-form-7');
        // A positional argument rather than --version, which Symfony reserves
        // globally for printing the application's own version.
        $this->addArgument('version', InputArgument::OPTIONAL, 'A specific version to pin (defaults to the current stable release)');
        $this->addOption('cache', null, InputOption::VALUE_REQUIRED, 'Where to keep downloaded plugins', 'sediment-plugins');
        $this->addOption('quiet-path', null, InputOption::VALUE_NONE, 'Print only the path, for use in scripts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = (string) $input->getArgument('slug');
        $version = $input->getArgument('version');
        $cache = (string) $input->getOption('cache');

        try {
            $result = (new WordPressOrgClient($cache))->fetch($slug, $version !== null ? (string) $version : null);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('quiet-path')) {
            $output->writeln($result['path'], OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $io->success(sprintf('%s %s %s', $slug, $result['version'], $result['cached'] ? '(already cached)' : 'downloaded'));
        $io->writeln('  path:   ' . $result['path']);
        $io->writeln('  sha256: ' . $result['sha256']);

        return Command::SUCCESS;
    }
}
