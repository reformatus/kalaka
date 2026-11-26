<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * A set of answers by field key, each as the form's controls send it (registrations-and-identity.md,
 * "Submissions and values"): the shape a submission keeps frozen and a draft keeps while it is filled in
 * (S74). Stored as one JSON object, never through `wp_json_encode()`, which would repair what it cannot
 * encode.
 */
final readonly class AnswerSet
{
    /** @param array<string, mixed> $answers field key → the answer as sent */
    public function __construct(public array $answers = [])
    {
    }

    /** The JSON object it is stored as. */
    public function toJson(): string
    {
        try {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() would repair invalid UTF-8
            return json_encode((object) $this->answers, $flags);
        } catch (\JsonException $e) {
            $error = new SystemFailure(null, $e);
            throw $error;
        }
    }

    /** @throws SystemFailure when the stored text is not a JSON object */
    public static function fromJson(string $json): self
    {
        $answers = json_decode($json, true);
        if (!is_array($answers)) {
            $error = new SystemFailure(null, new \UnexpectedValueException('Stored answers are not a JSON object.'));
            throw $error;
        }
        $keyed = [];
        foreach ($answers as $key => $answer) {
            $keyed[(string) $key] = $answer;
        }

        return new self($keyed);
    }

    /** These answers with `$over`'s written over them: a key both hold takes `$over`'s answer. */
    public function overwrittenBy(self $over): self
    {
        return new self(array_replace($this->answers, $over->answers));
    }

    public function isEmpty(): bool
    {
        return $this->answers === [];
    }
}
