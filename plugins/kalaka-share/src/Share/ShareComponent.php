<?php

declare(strict_types=1);

namespace Kalaka\Share;

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
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Share\Api\Share;

/**
 * The congregation share's fee component, at the share stage (money.md, "Congregation share"). For an event
 * with the share on, the registrant's answers decide: `share_mode` is `none`, `shared` (the amount in
 * `share_amount`) or `full` (the base price); no answer is `none`. The component writes one zero-sum pair of
 * undivided `share` lines, participant -X' and congregation +X', where X' = max(0, min(share, base - support)):
 * the base is the tier stage's total and the support the support stage's, so support lowers the participant's
 * part first and the congregation's only below zero. An X' of zero writes nothing, so an earlier pair is
 * reversed. It reads only the registration's facts (its two answers), its own setting and the stage totals,
 * never a charge line, and it does not check the congregation picker. The pair's lines are named by
 * `NamedFeeComponent`. [UNAPPROVED N144, N145, N150, N151, T66]
 */
final class ShareComponent implements ConfigurableFeeComponent, NamedFeeComponent
{
    public const ID = 'share';

    /** The payer kind of the congregation. */
    public const CONGREGATION = 'congregation';

    /** The two answer fields, extension-declared. */
    public const MODE_FIELD = 'share_mode';
    public const AMOUNT_FIELD = 'share_amount';

    /** The values of `share_mode`. */
    public const NONE = 'none';
    public const SHARED = 'shared';
    public const FULL = 'full';

    /** The name of both lines. [UNAPPROVED T66] */
    public const LINE_NAME = 'Gyülekezeti hozzájárulás';

    /** The most digits before the point an answer may have, so the amount never overflows. */
    private const MAX_INTEGER_DIGITS = 12;

    /** @param ?bool $proposed whether the share is on, to compose with instead of the stored setting (a preview) */
    public function __construct(
        private readonly ShareSettingsRepository $settings = new ShareSettingsRepository(),
        private readonly ?bool $proposed = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Share;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        if (!$this->enabled($input->registration->eventId)) {
            return FeeTargets::none();
        }
        $base = $input->before->of(FeeStage::Tier, Payer::PARTICIPANT);
        $left = $base->plus($input->before->of(FeeStage::Support, Payer::PARTICIPANT));
        $share = match (self::modeOf($input->facts)) {
            self::SHARED => self::amountOf($input),
            self::FULL => $base,
            default => null,
        };
        if ($share === null || !$share->isPositive() || !$left->isPositive()) {
            return FeeTargets::none();
        }
        $part = $share->isGreaterThan($left) ? $left : $share;

        return new FeeTargets([Payer::PARTICIPANT => $part->negated(), self::CONGREGATION => $part]);
    }

    /** Both lines of the pair are named alike (`LineName::COMPONENT`). [UNAPPROVED T66] */
    public function lineNames(RegistrationKey $registration, array $items): array
    {
        return in_array(null, $items, true) || in_array('', $items, true)
            ? ['' => new LineName(LineName::COMPONENT, self::LINE_NAME)]
            : [];
    }

    public function settingsToArray(int $eventId): array
    {
        return ['on' => $this->settings->find($eventId) !== null];
    }

    public function settingsFromArray(int $eventId, array $settings): ProposedComponentSettings
    {
        if (array_keys($settings) !== ['on'] || !is_bool($settings['on'])) {
            throw new InvalidInput();
        }

        return (new Share($this->settings))->propose($eventId, $settings['on']);
    }

    private function enabled(int $eventId): bool
    {
        return $this->proposed ?? $this->settings->find($eventId) !== null;
    }

    /** The answer of `share_mode`; `none` when it has none, or the form does not hold the field. */
    private static function modeOf(RegistrationFacts $facts): string
    {
        $kind = $facts->fieldKind(self::MODE_FIELD);
        if ($kind === null || $kind->block !== SetShare::CHOICE_TYPE || !$facts->has(self::MODE_FIELD)) {
            return self::NONE;
        }
        $data = $facts->get(self::MODE_FIELD)->data;

        return is_array($data) && count($data) === 1 && in_array($data[0], [self::SHARED, self::FULL], true)
            ? (string) $data[0]
            : self::NONE;
    }

    /** The answer of `share_amount`, or null: no such field, no value, or one that is not an amount. */
    private static function amountOf(FeeComponentInput $input): ?Money
    {
        $kind = $input->facts->fieldKind(self::AMOUNT_FIELD);
        if ($kind === null || !$kind->isNumber() || !$input->facts->has(self::AMOUNT_FIELD)) {
            return null;
        }
        $data = $input->facts->get(self::AMOUNT_FIELD)->data;
        $shape = '/\A[0-9]{1,' . self::MAX_INTEGER_DIGITS . '}(\.[0-9]{1,8})?\z/';
        if (!is_string($data) || preg_match($shape, $data) !== 1) {
            return null;
        }

        return Money::of($data, $input->currency);
    }
}
