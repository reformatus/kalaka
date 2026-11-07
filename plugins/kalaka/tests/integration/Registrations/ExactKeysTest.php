<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\FieldValue\FieldValue;

/** #42 AC11: `secret` and `field_key` are `utf8mb4_bin`, compared exactly. */
final class ExactKeysTest extends RegistrationsTestCase
{
    public function testEmailAndEmailInLowerCaseAreTwoFieldValuesOfOneRegistration(): void
    {
        $key = new RegistrationKey(5, '12A3');

        $this->inRun(function (array $input, Context $context) use ($key): void {
            $this->lockRegistrations($context, $key);
            $this->values->upsert(new FieldValue($key, 'Email', 'teszt.elek@example.test'), $context);
            $this->values->upsert(new FieldValue($key, 'email', 'teszt.anna@example.test'), $context);
        });

        global $wpdb;
        self::assertSame(
            [['Email', 'teszt.elek@example.test'], ['email', 'teszt.anna@example.test']],
            array_map(
                static fn (array $row): array => [$row['field_key'], $row['value']],
                (array) $wpdb->get_results(
                    "SELECT field_key, value FROM {$wpdb->prefix}kalaka_field_values"
                    . " WHERE event_id = 5 AND code = '12A3' ORDER BY field_key",
                    ARRAY_A
                )
            )
        );
        $email = $this->values->find(['eventId' => 5, 'code' => '12A3', 'fieldKey' => 'email']);
        self::assertInstanceOf(FieldValue::class, $email);
        self::assertSame('teszt.anna@example.test', $email->value);
    }

    public function testShowFullColumnsShowsTheBinaryCollation(): void
    {
        global $wpdb;

        foreach (['kalaka_registrations' => 'secret', 'kalaka_field_values' => 'field_key'] as $table => $column) {
            $row = $wpdb->get_row(
                $wpdb->prepare('SHOW FULL COLUMNS FROM %i WHERE Field = %s', $wpdb->prefix . $table, $column),
                ARRAY_A
            );
            self::assertIsArray($row, $table . '.' . $column);
            self::assertSame('utf8mb4_bin', $row['Collation'], $table . '.' . $column);
        }
    }
}
