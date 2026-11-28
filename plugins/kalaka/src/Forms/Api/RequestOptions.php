<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The options a request field offers, as its host gives them (`FormHost::requestOptions()`): the named
 * set's options in their order, each `{value, label}`, and the set's choice window. Outside the window the
 * field does not show and writes nothing (S44, C3).
 *
 * The shape the host hands the engine.
 */
final readonly class RequestOptions
{
    /**
     * @param list<array{value: string, label: string}> $options
     */
    public function __construct(
        public array $options,
        public ?\DateTimeImmutable $opens,
        public ?\DateTimeImmutable $closes,
    ) {
    }

    /** Whether the window holds the moment: from its opening (inclusive) to its closing (exclusive). */
    public function openAt(\DateTimeImmutable $now): bool
    {
        return ($this->opens === null || $now >= $this->opens) && ($this->closes === null || $now < $this->closes);
    }
}
