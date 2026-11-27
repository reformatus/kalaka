<?php

declare(strict_types=1);

namespace Kalaka\Deposits;

use Kalaka\Deposits\Cli\DepositCommand;
use Kalaka\Foundation\Api\Module\AbstractModule;

final class Module extends AbstractModule
{
    public function id(): string
    {
        return 'deposits';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=0.1 <0.2';
    }

    public function boot(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('kalaka deposit', DepositCommand::class);
        }
    }
}
