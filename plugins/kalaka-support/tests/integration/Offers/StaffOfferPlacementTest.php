<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Offers;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Admin\FieldEdit\EditFieldValue;
use Kalaka\Admin\FieldEdit\FieldEditInput;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Support\Offers\OfferSeatPool;

/**
 * B5-2, staff placing into an offer (T6): the same act as any field edit, on the offer's consent. A tick takes the
 * offer's seat (over the limit after the warning) and, for an offer that needs a request, awaits the answer; an
 * untick frees the seat and takes the line away; a re-tick after a refusal claims no seat.
 */
final class StaffOfferPlacementTest extends OffersTestCase
{
    private Actor $staff;

    private int $staffUser;

    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        $this->staffUser = $this->fullAccessUser();
        $this->staff = new UserActor($this->staffUser);
        $this->production = Runner::current();
        Runner::publish($this->runner);
        Access::forget();
        $this->applyOffers([
            self::offer('egyhaz', '10000', request: true, limit: 1),
            self::offer('diak', '5000', limit: 1),
        ]);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function tear_down(): void
    {
        Runner::publish($this->production);
        wp_set_current_user(0);
        Access::forget();

        parent::tear_down();
    }

    private function pool(string $offer = 'egyhaz'): OfferSeatPool
    {
        return new OfferSeatPool($this->event, $offer);
    }

    /**
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private function edit(RegistrationKey $key, string $offer, ?string $value, array $more = []): array
    {
        $input = [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'field' => $offer,
            'value' => $value,
            'reason' => 'Javítás',
        ] + $more;
        $edit = (new FieldEditInput())->prepare($input, $this->staff);
        $result = $this->runner->run(new EditFieldValue($edit), $input, $this->staff);
        self::assertIsArray($result);

        return $result;
    }

    /** @return array<string, mixed> the offer's detail field */
    private function offerField(RegistrationKey $key, string $offer): array
    {
        $read = (new RegistrationDetail())->of($this->staff, $key->eventId, $key->code);
        foreach ($read['fields'] as $field) {
            if ($field['key'] === $offer) {
                return $field;
            }
        }
        self::fail('No ' . $offer . ' in the read.');
    }

    private function stored(RegistrationKey $key, string $field): ?string
    {
        return (new Registrations())->values($key)[$field] ?? null;
    }

    public function testTheOffersConsentIsWritableAndItsFullFlagIsTheOffersPoolForThisRegistration(): void
    {
        $holder = $this->registerSelecting('00A0', 'egyhaz');
        $this->holdSeat($holder, 'egyhaz');
        $bela = $this->register('00A2');

        $field = $this->offerField($bela, 'egyhaz');
        self::assertTrue($field['writable']);
        self::assertSame('kalaka/consent-field', $field['edit']['answer']['kind']);
        self::assertNull($field['edit']['expected']);
        self::assertTrue($field['edit']['full']);
        // The offer with room is not full; the holder's own offer is not full for it.
        self::assertFalse($this->offerField($bela, 'diak')['edit']['full']);
        self::assertFalse($this->offerField($holder, 'egyhaz')['edit']['full']);
        // Only a registered registration holds seats: a withdrawn one is not warned.
        $gone = $this->register('00A5', \Kalaka\Registrations\Api\RegistrationStatus::Withdrawn);
        self::assertFalse($this->offerField($gone, 'egyhaz')['edit']['full']);
    }

    public function testTickingAFullRequestOfferTakesTheSeatOverTheLimitAndAwaitsTheAnswer(): void
    {
        $holder = $this->registerSelecting('00A0', 'egyhaz');
        $this->holdSeat($holder, 'egyhaz');
        $bela = $this->register('00A2');

        $preview = $this->edit($bela, 'egyhaz', '1', ['preview' => true]);
        self::assertSame(
            ['changed' => true, 'over_limit' => ['egyhaz'], 'warnings' => [AssignmentSets::FULL_WARNING]],
            $preview
        );
        self::assertFalse($this->holds($bela, 'egyhaz'));

        $result = $this->edit($bela, 'egyhaz', '1', ['expected' => null]);

        self::assertSame(['changed' => true, 'over_limit' => ['egyhaz']], $result);
        self::assertSame('1', $this->stored($bela, 'egyhaz'));
        self::assertTrue($this->holds($bela, 'egyhaz'));
        self::assertSame(1, $this->seats->over($this->pool()));
        // It awaits an answer: no line yet, nothing asked of the registrant.
        self::assertTrue($this->awaits($bela));
        self::assertSame([], $this->lineSources($bela));
        // Staff answer it with the one answer act.
        $this->answer($bela, 'egyhaz', 'yes');
        self::assertSame(['support:egyhaz participant -10000'], $this->lineSources($bela));
    }

    public function testUntickingFreesTheSeatTakesTheLineAwayAndKeepsTheAnswerRecord(): void
    {
        $bela = $this->registerSelecting('00A2', 'egyhaz');
        $this->holdSeat($bela, 'egyhaz');
        $this->answer($bela, 'egyhaz', 'yes');
        self::assertSame(['support:egyhaz participant -10000'], $this->lineSources($bela));

        $result = $this->edit($bela, 'egyhaz', '0', ['expected' => '1']);

        self::assertSame(['changed' => true, 'over_limit' => []], $result);
        self::assertSame('0', $this->stored($bela, 'egyhaz'));
        self::assertFalse($this->holds($bela, 'egyhaz'));
        self::assertSame([], $this->lineSources($bela));
        self::assertSame('30000', $this->balance($bela));
        self::assertSame('approved', $this->stored($bela, 'egyhaz_answer'), 'The answer act\'s record stays.');
    }

    public function testATickAfterARefusalClaimsNoSeat(): void
    {
        $bela = $this->registerSelecting('00A2', 'egyhaz');
        $this->holdSeat($bela, 'egyhaz');
        $this->answer($bela, 'egyhaz', 'no');
        self::assertSame('refused', $this->stored($bela, 'egyhaz_answer'));
        self::assertFalse($this->holds($bela, 'egyhaz'));
        $this->edit($bela, 'egyhaz', '0');

        $result = $this->edit($bela, 'egyhaz', '1');

        self::assertSame(['changed' => true, 'over_limit' => []], $result);
        self::assertFalse($this->holds($bela, 'egyhaz'));
        self::assertSame(0, $this->seats->held($this->pool()));
    }

    public function testAStaleExpectedIsAConflictAndNoSeatMoves(): void
    {
        $bela = $this->register('00A2');

        try {
            $this->edit($bela, 'diak', '1', ['expected' => '0']);
            self::fail('The screen showed another value.');
        } catch (VersionConflict) {
            self::assertFalse($this->holds($bela, 'diak'));
        }
    }

    public function testTheAbilityTicksAnOfferWithPreviewAndSave(): void
    {
        $holder = $this->registerSelecting('00A0', 'diak');
        $this->holdSeat($holder, 'diak');
        $bela = $this->register('00A2');
        wp_set_current_user($this->staffUser);
        $ability = wp_get_ability('kalaka/edit-field-value');
        self::assertInstanceOf(\WP_Ability::class, $ability);
        $input = [
            'event_id' => $bela->eventId,
            'code' => $bela->code,
            'field' => 'diak',
            'value' => '1',
            'expected' => $this->offerField($bela, 'diak')['edit']['expected'],
            'reason' => 'Javítás',
        ];

        self::assertSame(
            ['changed' => true, 'over_limit' => ['diak'], 'warnings' => [AssignmentSets::FULL_WARNING]],
            $ability->execute($input + ['preview' => true])
        );
        self::assertSame(['changed' => true, 'over_limit' => ['diak']], $ability->execute($input));
        self::assertSame(1, $this->seats->over($this->pool('diak')));
        self::assertSame(['support:diak participant -5000'], $this->lineSources($bela));
    }
}
