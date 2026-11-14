<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormTerminal;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The server's check of answers against a form's definition: the form walk (`FormWalk`, the same as
 * every re-render's), then any error refuses the answers. Only declared fields are accepted, a blank
 * answer is no answer, and `required` is applied here, not by the types; the answers of fields that
 * do not show and options that are not offered are dropped.
 */
final class AnswerValidator
{
    public function __construct(private readonly FormWalk $walk = new FormWalk())
    {
    }

    /**
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @param Facts|null $facts the facts besides the answers (the event's keys); none when null
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws FormTerminal a terminal page shows for the answers (MP-6)
     * @throws InvalidAnswers missing or wrong answers, all fields at once
     * @throws SystemFailure a field of a type that is no longer contributed, a key the facts do not declare
     */
    public function validate(
        FormDefinition $definition,
        array $answers,
        Contributions $contributions,
        ?Facts $facts = null
    ): ValidatedAnswers {
        $result = $this->walk->run($definition, $answers, $facts ?? new ValueContext(), $contributions);
        foreach ($definition->pages as $page) {
            // MP-6: a shown terminal page: the person could not have reached Küldés.
            if ($page->terminal && ($result->pages[$page->index] ?? false)) {
                $error = new FormTerminal();
                throw $error;
            }
        }
        if ($result->errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the messages are fixed texts, no input
            throw new InvalidAnswers($result->errors);
        }

        return new ValidatedAnswers($result->submitted, $result->stored);
    }
}
