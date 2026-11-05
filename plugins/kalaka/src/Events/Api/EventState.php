<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

/** The states of an event: events-and-forms.md, "Event lifecycle". */
enum EventState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';
}
