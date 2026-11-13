<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\Query\Query;

/**
 * The state row read fresh from the database, past every cache: the sender reads it before each claim,
 * so a pause another run set stops it after at most the attempt it has in hand (mail.md, "Sending").
 */
final class MailStateQuery extends Query
{
    public function current(): MailState
    {
        return $this->scope(function (): MailState {
            $rows = $this->select(
                'mail_state',
                'SELECT * FROM %i WHERE id = %d',
                [$this->table('kalaka_mail_states'), MailState::ID]
            );

            return isset($rows[0]) ? MailState::fromRow($rows[0]) : new MailState();
        });
    }
}
