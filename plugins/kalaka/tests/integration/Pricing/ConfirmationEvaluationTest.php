<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\ObjectCache;
use Kalaka\Foundation\Api\Decision\DecisionInput;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\FeeComposition;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\ReferenceCode;
use Kalaka\Tests\Integration\Foundation\Evaluation\TestRule;

/**
 * Confirmation inside the evaluation: money first, then the decision rules (core before extensions), then the
 * mail step, in the same pass; it reads the target through the read-only fee composition (no charge line, no
 * write), declares its approved labels, and costs the same number of statements whatever the event holds.
 */
final class ConfirmationEvaluationTest extends ConfirmationTestCase
{
    public function testThePassRunsMoneyThenConfirmationThenMail(): void
    {
        $seen = [];
        $probe = new TestRule('probe', ['seen' => 'Teszt']);
        $probe->onDecide = function (DecisionInput $input) use (&$seen): void {
            // An extension rule is asked after core's confirmation: the charge lines (money) and the
            // confirmation record are already written, the mail is not queued yet.
            $seen = [
                'charges' => count($this->charges($input->registration)),
                'confirmation' => $this->confirmations($input->registration),
                'deliveries' => count($this->deliveryRows()),
            ];
        };
        $this->useRules(static fn (DecisionRules $rules): null => $rules->add($probe, false));
        $this->confirmedMailOn();
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $seen = [];

        $this->payOn($anna, '10000', '2026-11-20');

        self::assertSame(1, $seen['charges'], 'The money phase ran first.');
        self::assertSame(['confirmed 2026-11-19 23:00:00 ledger_line'], $seen['confirmation']);
        self::assertSame(0, $seen['deliveries'], 'The mail step comes after every decision.');
        self::assertCount(1, $this->deliveryRows());
    }

    public function testAnExtensionRuleSeesConfirmationAsDecidedEarlierInThePass(): void
    {
        $place = new TestRule('support_place');
        $this->useRules(static fn (DecisionRules $rules): null => $rules->add($place, false));
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $place->inputs = [];

        $this->payOn($anna, '10000', '2026-11-20');

        self::assertSame('confirmed', $this->confirmationState($anna));
        self::assertCount(1, $place->inputs);
        self::assertSame('confirmed', $place->inputs[0]->decisions['confirmation']->state);
    }

    public function testACoreRuleReadsOnlyItsOwnKindAndTheCoreKindsBeforeIt(): void
    {
        $extension = new TestRule('ext_reader', ['on' => 'Be']);
        $extension->answer = 'on';
        $coreAfter = new TestRule('core_after');
        $this->useRules(static function (DecisionRules $rules) use ($extension): void {
            $rules->add($extension, false);
        });
        $this->rules->add($coreAfter, true);
        $coreAfter->inputs = [];

        // Nothing due: confirmation writes its record, and the core rule after it sees it, not the extension's.
        $this->register('00A0');

        self::assertSame(['confirmation'], array_keys($coreAfter->inputs[0]->decisions));
    }

    public function testTheCompositionIsReadOnlyAndReadsNoChargeLine(): void
    {
        $this->useComponents(new ShareComponent('3000'));
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '1000', '2026-11-20');
        $rule = new ConfirmationRule(new FeeComposition(new CoreFeeComponents([new ShareComponent('3000')])));

        $statements = $this->statementsDuring(static function () use ($rule, $anna): void {
            $rule->decide(new DecisionInput($anna, new \DateTimeImmutable('2026-12-01 10:00:00'), []));
        });

        self::assertNotSame([], $statements);
        foreach ($statements as $statement) {
            self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $statement);
            self::assertStringNotContainsString("'charge'", $statement);
        }
        $composed = (new FeeComposition(new CoreFeeComponents([new ShareComponent('3000')])))
            ->of($anna, new \DateTimeImmutable('2026-12-01 10:00:00'));
        self::assertNotNull($composed);
        self::assertSame(
            ['congregation' => '3000.00000000', 'participant' => '7000.00000000'],
            array_map(static fn (Money $amount): string => $amount->amount(), $composed->targets())
        );
    }

    public function testTheKindIsDeclaredWithItsApprovedLabels(): void
    {
        $kinds = (new Decisions(rules: $this->rules))->kinds();

        self::assertCount(1, $kinds);
        self::assertSame('confirmation', $kinds[0]->kind);
        self::assertSame('Visszaigazolás', $kinds[0]->label);
        self::assertSame(['confirmed' => 'Visszaigazolva', 'unconfirmed' => 'Nincs visszaigazolva'], $kinds[0]->states);
    }

    public function testTheDecisionCostsTheSameWhateverTheEventHolds(): void
    {
        $this->setPrice('10000');
        $anna = $this->register('00A0');
        $this->payOn($anna, '4000', '2026-11-20');
        $this->setFlag($anna);
        $alone = $this->decideStatements($anna);

        $codes = array_slice(ReferenceCode::fourCharacterCodes(), 10, 30);
        $this->seedCodes($this->event, $codes);
        foreach (array_slice($codes, 0, 5) as $code) {
            $key = new RegistrationKey($this->event, $code);
            $this->payOn($key, '1000', '2026-11-20');
            $this->setFlag($key);
        }
        $this->payOn($anna, '1000', '2026-11-21');
        $this->payOn($anna, '1000', '2026-11-22');

        self::assertCount(count($alone), $this->decideStatements($anna));
    }

    /** @return list<string> */
    private function decideStatements(RegistrationKey $key): array
    {
        $rule = new ConfirmationRule();
        // Each measure starts from a cold cache, so earlier runs' reads do not hide a statement.
        ObjectCache::clear();

        return $this->statementsDuring(static function () use ($rule, $key): void {
            $rule->decide(new DecisionInput($key, new \DateTimeImmutable('2026-12-01 10:00:00'), []));
        });
    }
}
