<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** Where a form sits in its event: events-and-forms.md, "Forms and the registration slot". */
enum FormPlacement: string
{
    case Registration = 'registration';
    case Additional = 'additional';
}
