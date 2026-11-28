<?php

declare(strict_types=1);

namespace Kalaka\Aid;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ConfigurableFeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\LineName;
use Kalaka\Pricing\Api\NamedFeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The adjudicated aid's fee component, at the aid stage (money.md, "Core money scope"). The award is the number
 * field `aid_award`, written only by `AidRequests` (the one answer act): an event with aid, an award above zero,
 * and the aid is one participant-side item line `aid:award`, a negative amount capped at what the participant
 * still owes after the tier, support and share stages and before the extras (Q-B0-8: capped, never refused). No
 * award, or an award of 0 (the answer "no"), writes no line; while the request awaits its answer the award field
 * is empty, so there is no reduction. It reads only the registration's facts and the event's settings (or the
 * proposed ones in a preview), never a charge line. The line is named by the event's aid label
 * (`NamedFeeComponent`). [UNAPPROVED N119]
 */
final class AidComponent implements ConfigurableFeeComponent, NamedFeeComponent
{
    public const ID = 'aid';

    /** The award participant field, extension-only. */
    public const AWARD_FIELD = 'aid_award';

    /** The item key of the aid line, so its source is `aid:award`. */
    public const ITEM = 'award';

    /**
     * @param ?array{label: ?string} $proposed the settings to compose with instead of the stored ones (a preview)
     */
    public function __construct(
        private readonly AidSettingsRepository $settings = new AidSettingsRepository(),
        private readonly ?array $proposed = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Aid;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        if (!$this->enabled($input->registration->eventId)) {
            return FeeTargets::none();
        }
        $award = self::awardOf($input);
        $part = $input->before->total(Payer::PARTICIPANT);
        if ($award === null || !$award->isPositive() || !$part->isPositive()) {
            return FeeTargets::none();
        }
        $amount = $award->isGreaterThan($part) ? $part : $award;

        return FeeTargets::ofItems([self::ITEM => [Payer::PARTICIPANT => $amount->negated()]]);
    }

    /**
     * The aid line is named by the event's aid label, the organizers' text (`LineName::COMPONENT`). An event with
     * no label has no aid and so no line.
     */
    public function lineNames(RegistrationKey $registration, array $items): array
    {
        $label = $this->settings->find($registration->eventId)?->label;
        if ($label === null || !in_array(self::ITEM, $items, true)) {
            return [];
        }

        return [self::ITEM => new LineName(LineName::COMPONENT, $label)];
    }

    public function settingsToArray(int $eventId): array
    {
        return ['label' => $this->settings->find($eventId)?->label];
    }

    public function settingsFromArray(int $eventId, array $settings): ProposedComponentSettings
    {
        if (array_keys($settings) !== ['label']) {
            throw new InvalidInput();
        }
        $label = $settings['label'];
        if ($label !== null && !is_string($label)) {
            throw new InvalidInput();
        }

        return (new Api\Aid($this->settings))->propose($eventId, $label);
    }

    private function enabled(int $eventId): bool
    {
        return $this->proposed !== null
            ? $this->proposed['label'] !== null
            : $this->settings->find($eventId) !== null;
    }

    /** The award in `aid_award`, or null: no such field, no value, or one that is not an amount. */
    private static function awardOf(FeeComponentInput $input): ?Money
    {
        $kind = $input->facts->fieldKind(self::AWARD_FIELD);
        if ($kind === null || !$kind->isNumber() || !$input->facts->has(self::AWARD_FIELD)) {
            return null;
        }
        $data = $input->facts->get(self::AWARD_FIELD)->data;
        $shape = '/^[0-9]{1,' . AidRequests::MAX_INTEGER_DIGITS . '}(\.[0-9]{1,8})?$/';
        if (!is_string($data) || preg_match($shape, $data) !== 1) {
            return null;
        }

        return Money::of($data, $input->currency);
    }
}
