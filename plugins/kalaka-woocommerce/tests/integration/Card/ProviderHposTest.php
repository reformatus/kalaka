<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

/** The card payment with WooCommerce's orders in its own tables (HPOS). */
final class ProviderHposTest extends ProviderTestBase
{
    protected function usesHpos(): bool
    {
        return true;
    }
}
