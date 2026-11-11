<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\Evaluation\EvaluationFailureQuery;
use Kalaka\Foundation\Evaluation\PeriodicEvaluation;
use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Foundation\Periodic\TimeRules;
use Kalaka\Mail\Module;
use Kalaka\Mail\Sender;

/** The sender as a step of the periodic run, and what the Mail module contributes. */
final class SenderStepTest extends MailTestCase
{
    private function periodicRun(Sender $sender): PeriodicRun
    {
        delete_option(PeriodicRun::LAST_RUN_OPTION);

        return new PeriodicRun(
            $this->clock,
            new PeriodicEvaluation($this->runner),
            new TimeRules(),
            new Events(),
            new EvaluationFailureQuery(),
            new BinlogCheck(),
            static fn (): array => [$sender]
        );
    }

    public function testThePeriodicRunSendsThroughTheSender(): void
    {
        $event = $this->published();
        $this->queue($event);

        self::assertNotNull($this->periodicRun($this->sender())->run());

        self::assertSame('sent', $this->deliveries()[0]['status']);
    }

    public function testTheSenderStillSendsWhenTheSweepThrows(): void
    {
        $event = $this->published();
        $this->queue($event);
        $throw = static function (string $sql): string {
            if (str_contains($sql, 'kalaka_evaluation_failures')) {
                throw new \RuntimeException('query failed');
            }

            return $sql;
        };
        add_filter('query', $throw);
        try {
            $this->periodicRun($this->sender())->run();
            self::fail('The sweep\'s exception should have left run().');
        } catch (\RuntimeException $e) {
            self::assertSame('query failed', $e->getMessage());
        } finally {
            remove_filter('query', $throw);
        }

        self::assertSame('sent', $this->deliveries()[0]['status']);
        self::assertFalse(get_option(PeriodicRun::LAST_RUN_OPTION));
    }

    public function testAFailingRecordEndsTheSenderRunButNotThePeriodicRun(): void
    {
        $event = $this->published();
        $this->queue($event, '00A0');
        $this->queue($event, '00B0');
        [$first, $second] = $this->deliveryIds();
        $composer = $this->composer;
        $this->composer->onRecipient = null;
        // The delivery is gone by the time its attempt is recorded.
        add_filter('pre_wp_mail', static function () use ($first): bool {
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'kalaka_deliveries', ['id' => $first]);

            return true;
        }, 5);

        try {
            $this->sender()->send();
            self::fail('The record should have failed.');
        } catch (NotFound) {
            self::assertSame([$second], $this->deliveryIds());
            self::assertSame('0', $this->delivery($second)['attempts'], 'The run ended at the failure.');
        }

        self::assertNotNull($this->periodicRun($this->sender())->run());
        self::assertIsString(get_option(PeriodicRun::LAST_RUN_OPTION));
        self::assertSame('sent', $this->delivery($second)['status']);
        self::assertSame([$first, $second], $composer->composed);
    }

    public function testTheModuleContributesTheSenderAndTheTwoWarnings(): void
    {
        $contributions = new Contributions();

        (new Module())->contribute($contributions);

        $steps = $contributions->periodicSteps();
        self::assertCount(1, $steps);
        self::assertInstanceOf(Sender::class, $steps[0]);
        self::assertSame('mail_sender', $steps[0]->name());
        self::assertSame(
            ['mail_paused_after_errors', 'mail_paused'],
            array_map(static fn ($warning): string => $warning->id(), $contributions->siteWarnings())
        );
    }

    public function testTheModulesSenderIdlesWithoutAComposer(): void
    {
        $event = $this->published();
        $this->queue($event);
        $contributions = new Contributions();
        (new Module())->contribute($contributions);
        $sender = $contributions->periodicSteps()[0];
        self::assertInstanceOf(Sender::class, $sender);

        self::assertNull($sender->send());

        self::assertSame('pending', $this->deliveries()[0]['status']);
    }
}
