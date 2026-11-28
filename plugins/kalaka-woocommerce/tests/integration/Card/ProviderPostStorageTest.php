<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

/** The card payment with WooCommerce's orders in posts (the legacy storage). */
final class ProviderPostStorageTest extends ProviderTestBase
{
    protected function usesHpos(): bool
    {
        return false;
    }
}
