<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * One request on a registration that needs a person's answer before its amount is final (money.md, "Sponsor and
 * church support"): the aid request, or a chosen support offer that needs approval. It is made on the form, as a
 * field value; a `RequestSource` says which requests a registration has now.
 *
 * `id` names the request for the answer act and keys its answers: `aid` for the aid request, `offer:<offer key>`
 * for an offer. `label` is the source's own Hungarian name for it, shown to staff. [UNAPPROVED N1]
 *
 * `askFields` are the participant field keys the participant asks it with (an offer's consent field, the aid
 * request's `aid_can_pay`), and `answerField` is the participant field key its answer is written to (null when
 * the source has none), both as stored keys: the admin detail groups a request's fields by them. Each source
 * fills them when it builds the request.
 */
final readonly class Request
{
    /** @param list<string> $askFields */
    public function __construct(
        public string $id,
        public RequestKind $kind,
        public string $label,
        public array $askFields = [],
        public ?string $answerField = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(:[a-z0-9_.-]+)?$/', $id) !== 1 || strlen($id) > 64) {
            throw new InvalidInput();
        }
    }
}
