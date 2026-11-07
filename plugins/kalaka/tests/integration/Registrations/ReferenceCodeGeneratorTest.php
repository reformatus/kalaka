<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\ReferenceCode;
use Kalaka\Registrations\ReferenceCodeGenerator;
use Kalaka\Registrations\Registration;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** AC3 to AC5: the generator, rejection sampling checked in SQL with an exact fallback. */
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

    public function testACodeNoOtherEventUsesIsPreferred(): void
    {
        $all = ReferenceCode::fourCharacterCodes();
        // Other events use every second code, so a batch of 64 holds a code no event uses all but surely.
        $used = array_values(array_filter($all, static fn (int $i): bool => $i % 2 === 0, ARRAY_FILTER_USE_KEY));
        $this->seedCodes(2, array_slice($used, 0, 1000));
        $this->seedCodes(3, array_slice($used, 1000));
        $unused = array_flip(array_diff($all, $used));
        $generator = new ReferenceCodeGenerator();

        for ($call = 0; $call < 50; $call++) {
            $code = $generator->generate(1);
            self::assertArrayHasKey($code, $unused);
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

    public function testAnEventWithOneCodeLeftGetsThatCode(): void
    {
        $all = ReferenceCode::fourCharacterCodes();
        $remaining = $all[1234];
        $this->seedCodes(1, array_values(array_diff($all, [$remaining])));
        // Every other event uses it: the event's own codes decide, not the site's.
        $this->seedCodes(2, [$remaining]);
        $generator = new ReferenceCodeGenerator();

        for ($call = 0; $call < 20; $call++) {
            self::assertSame($remaining, $generator->generate(1));
        }
    }

    public function testTheCodesLeftInANearlyFullEventAreIssuedUniformly(): void
    {
        $all = ReferenceCode::fourCharacterCodes();
        $free = [];
        foreach ([3, 400, 777, 1024, 1500, 2001, 2222, 2600, 3001, 3333, 3700, 4000, 4321, 4600, 4888, 4999] as $i) {
            $free[$all[$i]] = 0;
        }
        $this->seedCodes(1, array_values(array_diff($all, array_keys($free))));
        // A seeded engine, so the test is deterministic; production uses the default CSPRNG.
        $generator = new ReferenceCodeGenerator(random: new Randomizer(new Mt19937(20261107)));
        $draws = 40 * count($free);

        for ($call = 0; $call < $draws; $call++) {
            $code = $generator->generate(1);
            self::assertArrayHasKey($code, $free);
            $free[$code]++;
        }

        $expected = $draws / count($free);
        $chiSquare = 0.0;
        foreach ($free as $seen) {
            self::assertGreaterThan(0, $seen, 'Every free code is reachable.');
            $chiSquare += ($seen - $expected) ** 2 / $expected;
        }
        // The 99.9th percentile of chi-square with 15 degrees of freedom.
        self::assertLessThan(37.70, $chiSquare, 'Counts: ' . wp_json_encode($free));
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

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
    }
}
