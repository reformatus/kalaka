<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

/** The state row read once for the request and shared by the two warnings, so admin pages pay one read. */
final class RequestState
{
    private ?MailState $state = null;

    public function __construct(private readonly MailStateQuery $query = new MailStateQuery())
    {
    }

    public function get(): MailState
    {
        return $this->state ??= $this->query->current();
    }
}
