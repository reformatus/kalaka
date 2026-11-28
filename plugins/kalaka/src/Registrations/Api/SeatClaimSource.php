<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\ValidatedAnswers;

/**
 * Where a submit's seat claims come from: a module that builds a limited choice (a set option, a price tier or, from
 * #237, a support offer) contributes one (`Contributions::seatClaimSource()`), and `RegistrationSubmit` asks every
 * source for the claims of the answers it has just validated, before the transaction; an additional form's
 * submit asks them for the registration's values before and after it (`SubmitAdditionalForm::seatMove()`). A source
 * reads nothing but the answers, the event and its own pools' identity: it locks nothing, counts nothing and writes
 * nothing; the submit's use case locks the pools, counts their seats and takes them when the registration becomes
 * `registered`, or, for a `registered` registration's additional form, when its choice newly claims a pool.
 *
 * A source claims only what the registration is entitled to now: a choice that gives it no place at present (an
 * offer whose request was refused, say) makes no claim, whether the answers are a submit's or a registration's
 * stored values asked again at admission or restore.
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

    /**
     * Every pool the source can claim for the event, limited or not: the field a choice is made in, the option
     * value of the choice, and its pool. The form host serves `seats_left.<field key>.<option value>` from it.
     * A plain read; none when the source has no pools in the event.
     *
     * @return list<PoolEntry>
     */
    public function pools(Event $event): array;
}
