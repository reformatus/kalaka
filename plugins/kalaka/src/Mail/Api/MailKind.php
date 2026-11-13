<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/** The five kinds of mail: mail.md, "Five kinds of mail". */
enum MailKind: string
{
    case SelfSent = 'self_sent';
    case Action = 'action';
    case Manual = 'manual';
    case Reminder = 'reminder';
    case Scheduled = 'scheduled';
}
