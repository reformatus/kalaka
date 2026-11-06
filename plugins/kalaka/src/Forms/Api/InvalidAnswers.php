<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\KalakaException;

/** Answers that are missing or wrong, with the Hungarian message of each field. */
final class InvalidAnswers extends KalakaException
{
    /** @param array<string, string> $fields field key → message */
    public function __construct(public readonly array $fields, ?string $context = null, ?\Throwable $previous = null)
    {
        parent::__construct($context, $previous);
    }

    protected function defaultMessage(): string
    {
        return 'Néhány válasz hiányzik vagy hibás. Javítsd a megjelölt mezőket.';
    }

    public function errorCode(): string
    {
        return 'kalaka_invalid_answers';
    }

    public function status(): int
    {
        return 400;
    }

    public function isAudited(): bool
    {
        return false;
    }

    public function toWpError(): \WP_Error
    {
        return new \WP_Error(
            $this->errorCode(),
            $this->getMessage(),
            ['status' => $this->status(), 'fields' => $this->fields]
        );
    }
}
