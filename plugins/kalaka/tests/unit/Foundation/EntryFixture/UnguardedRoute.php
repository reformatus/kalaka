<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation\EntryFixture;

/** A REST route registered past the boundary: the entry-point check must find it. */
final class UnguardedRoute
{
    public function register(): void
    {
        register_rest_route('kalaka-fixture/v1', '/things', ['methods' => 'GET', 'callback' => '__return_null']);
    }
}
