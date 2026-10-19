<?php

declare(strict_types=1);

namespace Sediment;

use Sediment\Command\BatchCommand;
use Sediment\Command\CheckCommand;
use Sediment\Command\DiffCommand;
use Sediment\Command\FetchCommand;
use Sediment\Command\GradeCommand;
use Sediment\Command\IndexCommand;
use Sediment\Command\ScanCommand;
use Sediment\Command\UninstallCommand;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The Sediment console application: scan, grade, check, diff, batch, fetch, index, uninstall.
 */
final class Application extends BaseApplication
{
    public const NAME = 'sediment';

    /**
     * Kept in step with the top entry of CHANGELOG.md, which a test enforces —
     * this is stamped into every manifest as `analyzer_version`, so a stale value
     * silently mislabels the data downstream consumers keep.
     */
    public const VERSION = '0.12.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->add(new ScanCommand());
        $this->add(new GradeCommand());
        $this->add(new CheckCommand());
        $this->add(new DiffCommand());
        $this->add(new BatchCommand());
        $this->add(new FetchCommand());
        $this->add(new IndexCommand());
        $this->add(new UninstallCommand());
    }

    /**
     * A command line Symfony cannot parse (a missing argument, an unknown
     * option or command) exits 2, the same as any other usage error, so that
     * 1 always means a gate failed or the work could not be done.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ConsoleException $e) {
            if (!$this->areExceptionsCaught()) {
                throw $e;
            }

            $this->renderThrowable($e, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);

            return Command::INVALID;
        }
    }
}
