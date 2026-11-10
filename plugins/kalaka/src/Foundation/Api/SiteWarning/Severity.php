<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\SiteWarning;

/** Error: a red banner and a critical Site Health test. Info: an info banner. Errors show first. */
enum Severity: string
{
    case Error = 'error';
    case Info = 'info';
}
