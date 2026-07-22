<?php

declare(strict_types=1);

namespace Sediment\Command;

use Sediment\Analyzer\Scanner;
use Sediment\Generator\UninstallGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sediment uninstall <path>` — print a generated uninstall.php to stdout, so it
 * can be redirected straight to a file:
 *
 *     sediment uninstall ./my-plugin > uninstall.php
 *
 * Only stdout carries the PHP; any notice goes to stderr, keeping the redirect
 * clean.
 */
#[AsCommand(
    name: 'uninstall',
    description: 'Generate an uninstall.php covering what a plugin leaves behind.',
)]
final class UninstallCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the plugin directory to generate an uninstall.php for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if (!file_exists($path)) {
            $stderr->writeln("<error>Path not found: {$path}</error>");

            return Command::FAILURE;
        }

        $result = (new Scanner())->scan($path);
        $code = (new UninstallGenerator())->generate($result['findings'], $this->pluginName($path));

        $output->write($code, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function pluginName(string $path): string
    {
        $name = basename(rtrim($path, "/\\"));

        return $name !== '' ? $name : 'this plugin';
    }
}
