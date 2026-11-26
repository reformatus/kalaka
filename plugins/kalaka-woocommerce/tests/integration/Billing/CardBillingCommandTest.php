<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Billing;

use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Tests\Integration\WooCommerce\Card\WooTestCase;
use Kalaka\WooCommerce\BillingField;
use Kalaka\WooCommerce\Cli\CardBillingCommand;

/**
 * The trigger: `wp kalaka card-billing <event>` declares the five billing fields, and refuses an event that does
 * not exist.
 */
final class CardBillingCommandTest extends WooTestCase
{
    protected function usesHpos(): bool
    {
        return true;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        require_once dirname(__DIR__, 4) . '/kalaka/tests/integration/Foundation/Seed/WpCliDouble.php';
        parent::set_up();
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        $sources = [];
        foreach ((new ParticipantFields())->of($this->event, true) as $field) {
            $sources[$field->key] = $field->source;
        }

        return $sources;
    }

    public function testTheCommandDeclaresTheBillingFields(): void
    {
        (new CardBillingCommand())([(string) $this->event], []);

        self::assertSame(
            [
                BillingField::NAME => ParticipantField::SOURCE_EXTENSION,
                BillingField::COUNTRY => ParticipantField::SOURCE_EXTENSION,
                BillingField::POSTCODE => ParticipantField::SOURCE_EXTENSION,
                BillingField::CITY => ParticipantField::SOURCE_EXTENSION,
                BillingField::ADDRESS => ParticipantField::SOURCE_EXTENSION,
            ],
            $this->sources()
        );
        self::assertSame(
            [['success', 'Event ' . $this->event . ' has its billing participant fields.']],
            \WP_CLI::$calls
        );
    }

    public function testAnUnknownEventIsRefused(): void
    {
        try {
            (new CardBillingCommand())(['999999'], []);
            self::fail('An unknown event was accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }
        self::assertSame([['error', ['No event found: 999999', 1]]], \WP_CLI::$calls);
    }
}
