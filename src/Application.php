<?php

declare(strict_types=1);

namespace Sediment;

use Sediment\Command\BatchCommand;
use Sediment\Command\CheckCommand;
use Sediment\Command\DiffCommand;
use Sediment\Command\GradeCommand;
use Sediment\Command\ScanCommand;
use Sediment\Command\UninstallCommand;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * The Sediment console application: scan, grade, check, diff, batch, uninstall.
 */
final class Application extends BaseApplication
{
    public const NAME = 'sediment';

    /**
     * Kept in step with the top entry of CHANGELOG.md, which a test enforces —
     * this is stamped into every manifest as `analyzer_version`, so a stale value
     * silently mislabels the data downstream consumers keep.
     */
    public const VERSION = '0.5.1';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->add(new ScanCommand());
        $this->add(new GradeCommand());
        $this->add(new CheckCommand());
        $this->add(new DiffCommand());
        $this->add(new BatchCommand());
        $this->add(new UninstallCommand());
    }
}
