<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/** What a request asks for: a support offer that needs approval, or the aid request. [UNAPPROVED N1] */
enum RequestKind: string
{
    case Offer = 'offer';
    case Aid = 'aid';
}
