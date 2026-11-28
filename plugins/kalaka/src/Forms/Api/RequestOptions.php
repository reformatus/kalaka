<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The options a request field offers, as its host gives them (`FormHost::requestOptions()`): the named
 * set's options in their order, each `{value, label}`. A set has no window of its own; the form's own open
 * period and conditions decide when the field can be answered.
 *
 * The shape the host hands the engine.
 */
final readonly class RequestOptions
{
    /**
     * @param list<array{value: string, label: string}> $options
     */
    public function __construct(public array $options)
    {
    }
}
