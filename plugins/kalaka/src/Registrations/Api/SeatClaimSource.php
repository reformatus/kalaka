<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;

/**
 * Where a submit's seat claims come from: a module that builds a limited choice (a set option, later a price tier
 * or a support offer) contributes one (`Contributions::seatClaimSource()`), and `RegistrationSubmit` asks every
 * source for the claims of the answers it has just validated, before the transaction. A source reads nothing but
 * the answers, the event and its own pools' identity: it locks nothing, counts nothing and writes nothing; the
 * submit's use case locks the pools, counts their seats and takes them when the registration becomes
 * `registered` ([UNAPPROVED N8]).
 *
 * A source claims only what the registration is entitled to now: a choice that gives it no place at present (an
 * offer whose request was refused, say) makes no claim, whether the answers are a submit's or a registration's
 * stored values asked again at admission or restore ([UNAPPROVED R6]).
 */
interface SeatClaimSource
{
    /**
     * The claims of the answers: for each limited choice the registrant made, the field it was made in and the
     * pool it takes a seat in. None when the answers make no limited choice.
     *
     * @return list<SeatClaim>
     */
    public function claims(Event $event, ValidatedAnswers $answers): array;
}
