<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\ReferenceCode;
use Kalaka\Registrations\ReferenceCodeGenerator;
use Kalaka\Registrations\Registration;

require_once __DIR__ . '/RegistrationsTestCase.php';

/** AC3 to AC5: the generator. */
final class ReferenceCodeGeneratorTest extends RegistrationsTestCase
{
    public function testThreeRegistrationsOfOneRunGetThreeDistinctValidCodes(): void
    {
        $generator = new ReferenceCodeGenerator();

        $codes = $this->inRun(function (array $input, Context $context) use ($generator): array {
            $codes = [];
            for ($i = 0; $i < 3; $i++) {
                $code = $generator->generate(7);
                $codes[] = $code;
                $this->registrations->upsert($this->newRegistration(7, $code), $context);
            }

            return $codes;
        });

        self::assertIsArray($codes);
        self::assertCount(3, array_unique($codes));
        foreach ($codes as $code) {
            self::assertTrue(ReferenceCode::isValid($code));
            self::assertSame(4, strlen($code));
        }
        self::assertSame(3, $this->registrationCount());
    }

    public function testTheLastCodeNoOtherEventUsesIsTheOneIssued(): void
    {
        $all = ReferenceCode::fourCharacterCodes();
        $remaining = $all[1234];
        $others = array_values(array_diff($all, [$remaining]));
        $this->seedCodes(2, array_slice($others, 0, 2000));
        $this->seedCodes(3, array_slice($others, 2000));
        $generator = new ReferenceCodeGenerator();

        for ($call = 0; $call < 20; $call++) {
            self::assertSame($remaining, $generator->generate(1));
        }
    }

    public function testWhenOtherEventsUseEveryCodeTheEventsOwnAreStillAvoided(): void
    {
        $all = ReferenceCode::fourCharacterCodes();
        $this->seedCodes(2, $all);
        $own = array_slice($all, 100, 10);
        $this->seedCodes(1, $own);
        $generator = new ReferenceCodeGenerator();

        for ($call = 0; $call < 20; $call++) {
            $code = $generator->generate(1);
            self::assertSame(4, strlen($code));
            self::assertTrue(ReferenceCode::isValid($code));
            self::assertNotContains($code, $own);
        }
    }

    public function testAnEventThatUsesEveryFourCharacterCodeGetsAFiveCharacterOneAndNothingIsStored(): void
    {
        $this->seedCodes(1, ReferenceCode::fourCharacterCodes());
        $generator = new ReferenceCodeGenerator();
        $before = $this->registrationCount();
        $events = $this->rowsIn('kalaka_events');
        $options = $this->rowsIn('options');

        $statements = $this->statementsDuring(function () use ($generator, &$code): void {
            $code = $generator->generate(1);
        });

        self::assertIsString($code);
        self::assertSame(5, strlen($code));
        self::assertTrue(ReferenceCode::isValid($code));
        self::assertSame($before, $this->registrationCount());
        self::assertSame($events, $this->rowsIn('kalaka_events'));
        self::assertSame($options, $this->rowsIn('options'));
        foreach ($statements as $statement) {
            self::assertStringStartsWith('SELECT', $statement);
        }
    }

    public function testAnExtendedCodeIsInsertedLikeAnyOther(): void
    {
        $this->seedCodes(1, ReferenceCode::fourCharacterCodes());
        $code = (new ReferenceCodeGenerator())->generate(1);

        $stored = $this->insertRegistration($this->newRegistration(1, $code));

        self::assertInstanceOf(Registration::class, $stored);
        self::assertSame($code, $stored->key->code);
    }

    private function rowsIn(string $table): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
    }
}
