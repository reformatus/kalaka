<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Share;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Evaluation\EvaluateRegistration;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ApplyReport;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Support\Api\Share;
use Kalaka\Tests\Integration\Support\Offers\OffersTestCase;

/**
 * The base of the congregation share tests: the support-offer test case (an event in HUF, the money phase with the
 * `support` and `share` components, a directory node) with the base price 44 500 Ft, and helpers to turn the
 * share on through a money settings apply, answer its two questions and read its lines.
 */
abstract class ShareTestCase extends OffersTestCase
{
    protected Share $share;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();
        $this->share = new Share();
        $this->setPrice('44500');
    }

    /** Turns the event's share on (or off) through a money settings apply. */
    protected function applyShare(bool $on = true): ApplyReport
    {
        $proposed = new ProposedMoneySettings(components: [$this->share->propose($this->event, $on)]);

        return $this->settings->apply($this->event, $proposed, new CliActor(null));
    }

    /** A registered registration with its two answers, evaluated; the amount only when given. */
    protected function registerSharing(string $code, ?string $mode, ?string $amount = null): RegistrationKey
    {
        $key = $this->register($code);
        $this->answerShare($key, $mode, $amount);

        return $key;
    }

    protected function answerShare(RegistrationKey $key, ?string $mode, ?string $amount = null): void
    {
        if ($amount !== null) {
            $this->writeValue($key, 'share_amount', $amount);
        }
        if ($mode !== null) {
            $this->writeValue($key, 'share_mode', $mode);
        }
    }

    protected function evaluateNow(RegistrationKey $key): void
    {
        $this->runner->run(
            new EvaluateRegistration(),
            ['event_id' => $key->eventId, 'code' => $key->code],
            new CliActor(null)
        );
    }

    /**
     * The net of the share's charge lines as "payer amount", nets of zero left out.
     *
     * @return list<string>
     */
    protected function shareLines(RegistrationKey $key): array
    {
        $net = [];
        foreach ($this->charges($key) as $line) {
            [$source, $payer, $amount] = explode(' ', $line);
            if ($source === 'share') {
                $net[$payer] = ($net[$payer] ?? 0) + (int) $amount;
            }
        }
        $lines = [];
        foreach ($net as $payer => $amount) {
            if ($amount !== 0) {
                $lines[] = $payer . ' ' . $amount;
            }
        }
        sort($lines);

        return $lines;
    }

    protected function congregationBalance(RegistrationKey $key): string
    {
        return $this->balance($key, 'congregation');
    }

    protected function currency(): Currency
    {
        return Currency::HUF;
    }

    protected function participant(): string
    {
        return Payer::PARTICIPANT;
    }
}
