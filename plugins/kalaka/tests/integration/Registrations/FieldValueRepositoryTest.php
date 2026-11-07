<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\FieldValue\FieldValue;

/** AC9: field values as items on their registration. */
final class FieldValueRepositoryTest extends RegistrationsTestCase
{
    public function testEachAnswerIsAnAuditItemOfItsOwnAndEveryWriteMarksTheRegistration(): void
    {
        $key = new RegistrationKey(5, '12A3');
        $this->seedRegistrations($key);
        $touched = null;

        $this->inRun(function (array $input, Context $context) use ($key, &$touched): void {
            $this->lockRegistrations($context, $key);
            $this->values->upsert(new FieldValue($key, 'name', 'Árvíztűrő Tükörfúrógép'), $context);
            $this->values->upsert(new FieldValue($key, 'email', 'anna@example.test'), $context);
            $touched = $context->touchedRegistrations();
        });
        self::assertEquals([$key], $touched);

        $first = $this->onlyEntry();
        self::assertSame(['registration/5/12A3'], $this->subjectsOf($first['id']));
        self::assertSame(
            [
                [
                    'type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'field:name',
                    'before' => null, 'after' => 'Árvíztűrő Tükörfúrógép',
                ],
                [
                    'type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'field:email',
                    'before' => null, 'after' => 'anna@example.test',
                ],
            ],
            $this->decode($first['changes'])
        );

        $this->inRun(function (array $input, Context $context) use ($key, &$touched): void {
            $email = $this->values->find(['eventId' => 5, 'code' => '12A3', 'fieldKey' => 'email']);
            self::assertInstanceOf(FieldValue::class, $email);
            $this->lockRegistrations($context, $key);
            $email->value = 'anna@example.net';
            self::assertSame(2, $this->values->upsert($email, $context)->version);
            $touched = $context->touchedRegistrations();
        });
        self::assertEquals([$key], $touched);

        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertSame(
            [[
                'type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'field:email',
                'before' => 'anna@example.test', 'after' => 'anna@example.net',
            ]],
            $this->decode($entries[1]['changes'])
        );
    }

    public function testDeletingAnAnswerRecordsOneItemNamedAfterItsField(): void
    {
        $this->seedRegistrations(new RegistrationKey(5, '12A3'));
        $this->inRun(function (array $input, Context $context): void {
            $this->lockRegistrations($context, new RegistrationKey(5, '12A3'));
            $this->values->upsert(
                new FieldValue(new RegistrationKey(5, '12A3'), 'email', 'anna@example.test'),
                $context
            );
        });

        $this->inRun(function (array $input, Context $context): void {
            $email = $this->values->find(['eventId' => 5, 'code' => '12A3', 'fieldKey' => 'email']);
            self::assertInstanceOf(FieldValue::class, $email);
            $this->lockRegistrations($context, new RegistrationKey(5, '12A3'));
            $this->values->delete($email, $context);
        });

        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertSame(
            [[
                'type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'field:email',
                'before' => 'anna@example.test', 'after' => null,
            ]],
            $this->decode($entries[1]['changes'])
        );
    }

    public function testTheValuesOfOneRegistrationComeInOneStatementAndOnlyThose(): void
    {
        $key = new RegistrationKey(5, '12A3');
        $this->seedRegistrations($key, new RegistrationKey(5, '13A2'), new RegistrationKey(6, '12A3'));
        $this->inRun(function (array $input, Context $context) use ($key): void {
            $this->lockRegistrations($context, $key, new RegistrationKey(5, '13A2'), new RegistrationKey(6, '12A3'));
            $this->values->upsert(new FieldValue($key, 'name', 'Árvíztűrő Tükörfúrógép'), $context);
            $this->values->upsert(new FieldValue($key, 'email', 'anna@example.test'), $context);
            $this->values->upsert(new FieldValue(new RegistrationKey(5, '13A2'), 'name', 'Béla'), $context);
            $this->values->upsert(new FieldValue(new RegistrationKey(6, '12A3'), 'name', 'Cecil'), $context);
        });

        $found = [];
        $statements = $this->statementsDuring(function () use ($key, &$found): void {
            $found = $this->values->forRegistration($key);
        });

        self::assertCount(1, $statements);
        $byKey = [];
        foreach ($found as $value) {
            $byKey[$value->fieldKey] = $value->value;
        }
        self::assertSame(['email' => 'anna@example.test', 'name' => 'Árvíztűrő Tükörfúrógép'], $byKey + []);
        self::assertCount(2, $found);
    }

    /** @return iterable<string, array{string}> */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield '65 characters' => [str_repeat('k', 65)];
    }

    /** @dataProvider badKeys */
    public function testAFieldKeyOfTheWrongLengthIsASystemFailureAndStoresNothing(string $fieldKey): void
    {
        global $wpdb;

        try {
            $this->inRun(function (array $input, Context $context) use ($fieldKey): void {
                $this->values->upsert(new FieldValue(new RegistrationKey(5, '12A3'), $fieldKey, 'x'), $context);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
        }

        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_field_values"));
    }

    public function testAKeyOf64CharactersIsStored(): void
    {
        $fieldKey = str_repeat('é', 64);
        $this->seedRegistrations(new RegistrationKey(5, '12A3'));
        $this->inRun(function (array $input, Context $context) use ($fieldKey): void {
            $this->lockRegistrations($context, new RegistrationKey(5, '12A3'));
            $this->values->upsert(new FieldValue(new RegistrationKey(5, '12A3'), $fieldKey, 'x'), $context);
        });

        self::assertSame('x', $this->values->find(['eventId' => 5, 'code' => '12A3', 'fieldKey' => $fieldKey])?->value);
    }
}
