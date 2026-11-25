<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\KalakaException;

/** Answers that are missing or wrong, with the Hungarian message of each field. */
final class InvalidAnswers extends KalakaException
{
    /**
     * @param array<string, string> $fields field key → message
     * @param array<string, string> $codes field key → the error code of a refusal that has its own (a subset of
     *     the keys of `$fields`); a field without one is a plain answer error
     */
    public function __construct(
        public readonly array $fields,
        ?string $context = null,
        ?\Throwable $previous = null,
        public readonly array $codes = [],
    ) {
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
        $data = ['status' => $this->status(), 'fields' => $this->fields];
        if ($this->codes !== []) {
            $data['codes'] = $this->codes;
        }

        return new \WP_Error($this->errorCode(), $this->getMessage(), $data);
    }
}
