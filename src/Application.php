<?php

declare(strict_types=1);

namespace Sediment;

use Sediment\Command\GradeCommand;
use Sediment\Command\ScanCommand;
use Sediment\Command\UninstallCommand;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * The Sediment console application. Registers the command surface: scan, grade,
 * and uninstall. (A `check --fail-on=<grade>` command for CI is on the roadmap.)
 */
final class Application extends BaseApplication
{
    public const NAME = 'sediment';
    public const VERSION = '0.1.0-dev';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->add(new ScanCommand());
        $this->add(new GradeCommand());
        $this->add(new UninstallCommand());
    }
}
