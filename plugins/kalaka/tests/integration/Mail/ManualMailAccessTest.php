<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Tests\Integration\Admin\GrantTestCase;

/** Who may send a manual mail to whom: the grant action `kalaka_manual_mail`, inside the grant's view. */
final class ManualMailAccessTest extends GrantTestCase
{
    private const ACTION = 'kalaka_manual_mail';

    private int $event;

    private int $user;

    private int $body;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        ActionCatalogue::record(new ActionCatalogue([self::EDIT, self::ACTION]));
        $this->event = $this->createEvent();
        $this->user = $this->plainUser();
        $this->addRegistration($this->event, '00A0');
        $this->addValue($this->event, '00A0', 'module', 'építő');
        $this->addRegistration($this->event, '00A2');
        $this->addValue($this->event, '00A2', 'module', 'konyha');
        $id = wp_insert_post([
            'post_type' => 'kalaka_mail',
            'post_status' => 'publish',
            'post_title' => 'Levél',
            'post_content' => '<!-- wp:paragraph --><p>Szia</p><!-- /wp:paragraph -->',
            'meta_input' => ['kalaka_event_id' => $this->event],
        ]);
        self::assertIsInt($id);
        $this->body = $id;
    }

    /** @param list<string> $codes */
    private function sends(array $codes, ?\Kalaka\Foundation\Api\Actor\Actor $actor = null, ?int $event = null): bool
    {
        try {
            (new ManualMails())->send(
                ['event_id' => $event ?? $this->event, 'body_id' => $this->body, 'codes' => $codes],
                $actor ?? new UserActor($this->user),
                $this->runner
            );
        } catch (PermissionDenied) {
            return false;
        }

        return true;
    }

    private function moduleGrant(string $module, array $actions = [self::ACTION]): void
    {
        $this->saveGrant($this->event, $this->user, [
            'view_id' => $this->parameterView($this->event),
            'bound_value' => $module,
            'actions' => $actions,
        ]);
    }

    public function testFullAccessTheSystemAndTheCommandLineSend(): void
    {
        self::assertTrue($this->sends(['00A0', '00A2'], $this->admin));
        self::assertTrue($this->sends(['00A0'], new CliActor(null)));
        self::assertTrue($this->sends(['00A0'], new SystemActor()));
    }

    public function testAGrantWithTheActionSendsToRegistrationsInsideItsView(): void
    {
        $this->moduleGrant('építő');

        self::assertTrue($this->sends(['00A0']));
        self::assertTrue($this->sends(['00A0'], new CliActor($this->user)));
    }

    public function testOneCodeOutsideTheViewRefusesTheWholeBatchAndTheRefusalIsAudited(): void
    {
        $this->moduleGrant('építő');

        self::assertFalse($this->sends(['00A0', '00A2']));
        self::assertSame([], $this->deliveries());
        $refused = array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === 'kalaka/send-manual-mail'
        ));
        self::assertCount(1, $refused);
        self::assertNotSame('succeeded', $refused[0]['outcome']);
    }

    public function testAGrantWithoutTheActionOrWithoutAGrantOnTheEventIsRefused(): void
    {
        $this->moduleGrant('építő', [self::EDIT]);
        self::assertFalse($this->sends(['00A0']));

        $other = $this->createEvent('Másik');
        $this->saveGrant($other, $this->user, ['actions' => [self::ACTION]]);
        self::assertFalse($this->sends(['00A0']));
    }

    public function testAUserWithoutAnyGrantIsRefused(): void
    {
        self::assertFalse($this->sends(['00A0']));
    }

    public function testThePreviewReadsTheSanitizedInputTheRunnerChecks(): void
    {
        $input = ['event_id' => (string) $this->event, 'codes' => ['00A0']];

        $checked = Runner::checkedInput(['type' => 'object', 'properties' => [
            'event_id' => ['type' => 'integer'],
            'codes' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]], $input, 'test');
        $plan = (new ManualMails())->preview($input, $this->admin);

        self::assertSame($this->event, $checked['event_id']);
        self::assertSame(['00A0'], $plan['queue']);
        $this->expectException(\Kalaka\Foundation\Api\Error\InvalidInput::class);
        (new ManualMails())->preview($input + ['body_id' => $this->body], $this->admin);
    }

    /** @return list<array<string, ?string>> */
    private function deliveries(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kalaka_deliveries", ARRAY_A);

        return array_values(is_array($rows) ? $rows : []);
    }
}
