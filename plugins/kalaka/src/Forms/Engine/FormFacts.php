<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;

/** A form's fact source while it is walked: the answers so far, then the caller's facts (the event's). */
final readonly class FormFacts implements Facts
{
    public function __construct(
        private ValueContext $answers,
        private Facts $base,
    ) {
    }

    public function with(string $key, Value $value): self
    {
        return new self($this->answers->with($key, $value), $this->base);
    }

    public function get(string $key): Value
    {
        return $this->answers->has($key) ? $this->answers->get($key) : $this->base->get($key);
    }
}
