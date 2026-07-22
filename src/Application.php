<?php

declare(strict_types=1);

namespace Sediment;

use Sediment\Command\ScanCommand;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * The Sediment console application. Registers the command surface described in
 * the spec (§2): scan, grade, uninstall, check. Only `scan` exists in the spike.
 */
final class Application extends BaseApplication
{
    public const NAME = 'sediment';
    public const VERSION = '0.1.0-dev';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->add(new ScanCommand());
    }
}
