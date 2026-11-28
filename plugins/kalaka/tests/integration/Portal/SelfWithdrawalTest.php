<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Portal;

use Kalaka\Foundation\Api\Error\SiteClosed;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Pricing\SetManualConfirmation;
use Kalaka\Pricing\SetRetention;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Portal\ActiveRegistrationState;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Portal\Api\WithdrawBadNonce;
use Kalaka\Portal\Api\WithdrawStale;
use Kalaka\Portal\Api\WithdrawUnavailable;
use Kalaka\Portal\Api\Withdrawal;
use Kalaka\Portal\WithdrawRefusalCookie;
use Kalaka\Portal\WithdrawRefusalState;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\StatusActRefused;
use Kalaka\Registrations\Api\WithdrawClosed;
use Kalaka\Registrations\Api\OwnWithdrawal;
use Kalaka\Tests\Integration\Admin\FieldEdit\StaffEditTestCase;

/**
 * The participant's own withdrawal (`kalaka-portal/withdraw-registration`): the portal check, the act (the staff
 * withdrawal's own leave), the preview of the registrant's own payer, the route and `can.withdraw`.
 */
final class SelfWithdrawalTest extends StaffEditTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function set_up(): void
    {
        parent::set_up();

        $money = new Materializer(new CoreFeeComponents([]));
        $rules = new DecisionRules();
        $rules->add(new ConfirmationRule($money->composition()), true);
        $this->runner = new Runner($this->clockAt('2026-12-01 10:00:00'), true, new Evaluation($rules, money: $money));
        $this->production = Runner::current();
        Runner::publish($this->runner);
        ActiveRegistrationState::set(null, false);
        WithdrawRefusalState::set(null);
        $GLOBALS['wp_rest_server'] = null;
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '8000', 'currency' => 'HUF']);
        $this->addRegistration($this->event, '00A0');
        $this->addRegistration($this->event, '00A2');
        $this->addRegistration($this->event, '00A5', 'waitlisted');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function tear_down(): void
    {
        LoadState::record([]);
        ActiveRegistrationState::set(null, false);
        WithdrawRefusalState::set(null);
        $GLOBALS['wp_rest_server'] = null;
        Runner::publish($this->production);

        parent::tear_down();
    }

    private function withdrawal(): Withdrawal
    {
        return new Withdrawal(new OwnWithdrawal($this->runner));
    }

    /** @return array<string, mixed> */
    private function withdraw(string $code, ?string $as = null, bool $preview = false): array
    {
        return $this->withdrawal()->run(
            $this->key($as ?? $code),
            $code,
            $preview,
            Withdrawal::nonceValue($code)
        );
    }

    /** The form post's body with the page's nonce for that code: a real withdrawal always carries one. */
    private function formBody(string $code, string $extra = ''): string
    {
        $body = 'code=' . rawurlencode($code) . '&' . Withdrawal::NONCE_FIELD . '='
            . rawurlencode(Withdrawal::nonceValue($code));

        return $extra === '' ? $body : $body . '&' . $extra;
    }

    private function status(string $code): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM %i WHERE event_id = %d AND code = %s',
            $wpdb->prefix . 'kalaka_registrations',
            $this->event,
            $code
        ));
    }

    private function tableRows(string $table): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . $table));
    }

    /** @return array<string, int> what a preview must leave as it was */
    private function counts(): array
    {
        return [
            'audit' => $this->tableRows('kalaka_audit_entries'),
            'seats' => $this->tableRows('kalaka_seats'),
            'ledger' => $this->tableRows('kalaka_ledger_lines'),
            'decisions' => $this->tableRows('kalaka_decisions'),
            'registrations' => (int) $this->version('00A0') + (int) $this->version('00A5'),
        ];
    }

    private function version(string $code): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT version FROM %i WHERE event_id = %d AND code = %s',
            $wpdb->prefix . 'kalaka_registrations',
            $this->event,
            $code
        ));
    }

    private function pay(string $code, string $amount): void
    {
        $this->exec(new RecordPayment(), [
            'event_id' => $this->event,
            'code' => $code,
            'amount' => $amount,
            'currency' => 'HUF',
            'method' => 'bank_transfer',
            'value_date' => '2026-11-29',
            'idempotency_key' => 'k-' . strtolower($code) . '-' . $amount,
        ]);
    }

    private function payCongregation(string $code, string $amount): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_ledger_lines', [
            'event_id' => $this->event,
            'code' => $code,
            'kind' => 'payment',
            'payer' => 'congregation',
            'amount' => '-' . $amount,
            'currency' => 'HUF',
            'source_type' => 'system',
            'source_event_id' => null,
            'source_key' => 'system',
            'idempotency_key' => 'gyulekezet-' . $code,
            'value_date' => '2026-11-29 23:00:00.000000',
            'paid_at' => null,
            'recorded_at' => '2026-11-30 08:00:00.000000',
            'method' => 'bank_transfer',
            'correction' => 0,
        ]);
    }

    public function testAWaitlistedRegistrantWithdrawsAndTheAuditNamesTheSecretLink(): void
    {
        $before = $this->tableRows('kalaka_audit_entries');
        $result = $this->withdraw('00A5');

        self::assertSame(['changed' => true, 'status' => 'withdrawn'], $result);
        self::assertSame('withdrawn', $this->status('00A5'));
        $entry = $this->lastEntry();
        self::assertSame('kalaka-portal/withdraw-registration', $entry['action']);
        self::assertSame('secret_link', $entry['actor_type']);
        self::assertSame('00A5', $entry['actor_code']);
        self::assertNull($entry['reason']);
        self::assertSame($before + 1, $this->tableRows('kalaka_audit_entries'));
    }

    public function testARegisteredRegistrantWithdrawsFreesTheSeatsAndQueuesNoMail(): void
    {
        $this->addValue($this->event, '00A0', 'tier', 'a');
        $this->seat('00A0', $this->poolA);
        $before = $this->version('00A0');
        global $wpdb;
        $mails = $this->tableRows('kalaka_deliveries');

        $result = $this->withdraw('00A0');

        self::assertSame(['changed' => true, 'status' => 'withdrawn'], $result);
        self::assertSame('withdrawn', $this->status('00A0'));
        self::assertSame((int) $before + 1, (int) $this->version('00A0'));
        self::assertSame(0, $this->seats->held($this->poolA));
        self::assertFalse($this->seats->holds($this->poolA, $this->key('00A0')));
        self::assertSame($mails, $this->tableRows('kalaka_deliveries'));
        // The registered stamp is the staff withdrawal's: left as it was.
        self::assertNotNull($wpdb->get_var($wpdb->prepare(
            'SELECT registered_at FROM %i WHERE event_id = %d AND code = %s',
            $wpdb->prefix . 'kalaka_registrations',
            $this->event,
            '00A0'
        )));
        // Nobody on the waitlist moved up.
        self::assertSame('waitlisted', $this->status('00A5'));
    }

    public function testTheEvaluationFollowsAndTheEventsRetentionRuleAppliesToTheOwnWithdrawal(): void
    {
        $this->exec(new SetRetention(), ['event_id' => $this->event, 'retention' => 'nothing']);
        $this->pay('00A0', '3000');

        $this->withdraw('00A0');

        $summary = (new \Kalaka\Ledger\Api\Ledger())->summaryOf($this->key('00A0'))
            ->of('participant', \Kalaka\Foundation\Api\Money\Currency::HUF);
        // The staff withdrawal's own result: nothing retained, so what was paid shows as an overpayment.
        self::assertSame('3000.00000000', $summary->paid->amount());
        self::assertSame('-3000.00000000', $summary->balance->amount());
    }

    public function testTheOwnWithdrawalClearsTheManualConfirmationLikeAStaffLeaving(): void
    {
        $this->exec(new SetManualConfirmation(), ['event_id' => $this->event, 'code' => '00A0', 'reason' => 'Teszt']);
        global $wpdb;
        $revoked = fn (): ?string => $wpdb->get_var($wpdb->prepare(
            'SELECT revoked_at FROM %i WHERE event_id = %d AND code = %s',
            $wpdb->prefix . 'kalaka_manual_confirmations',
            $this->event,
            '00A0'
        ));
        self::assertNull($revoked());

        $this->withdraw('00A0');

        self::assertNotNull($revoked());
        self::assertStringContainsString('revoked_at', (string) $this->lastEntry()['changes']);
    }

    public function testAWaitlistedWithdrawalHoldsAndFreesNoSeat(): void
    {
        $seats = $this->tableRows('kalaka_seats');

        $this->withdraw('00A5');

        self::assertSame($seats, $this->tableRows('kalaka_seats'));
    }

    public function testWithdrawingAgainOrAfterAStaffRemovalIsUnavailable(): void
    {
        $this->withdraw('00A0');
        try {
            $this->withdraw('00A0');
            self::fail('A withdrawn registration cannot withdraw again.');
        } catch (WithdrawUnavailable $e) {
            self::assertSame('kalaka_withdraw_unavailable', $e->errorCode());
        }

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_registrations', ['status' => 'removed'], [
            'event_id' => $this->event,
            'code' => '00A2',
        ]);
        $this->expectException(WithdrawUnavailable::class);
        $this->withdraw('00A2');
    }

    public function testAnotherCodeThanTheActiveRegistrationsIsRefusedAndNothingChanges(): void
    {
        $this->expectException(WithdrawStale::class);
        try {
            $this->withdrawal()->run($this->key('00A0'), '00A2', false, Withdrawal::nonceValue('00A2'));
        } finally {
            self::assertSame('registered', $this->status('00A2'));
            self::assertSame('registered', $this->status('00A0'));
        }
    }

    public function testWithoutAnActiveRegistrationItIsRefused(): void
    {
        $this->expectException(WithdrawStale::class);
        $this->withdrawal()->run(null, '00A0', false, Withdrawal::nonceValue('00A0'));
    }

    public function testAStatusThatChangesUnderTheLockIsTheStaffActsRefusal(): void
    {
        // The use case itself, past the portal's check: a status that cannot withdraw is the status act's refusal.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_registrations', ['status' => 'removed'], [
            'event_id' => $this->event,
            'code' => '00A2',
        ]);

        $this->expectException(StatusActRefused::class);
        (new OwnWithdrawal($this->runner))->run($this->key('00A2'));
    }

    public function testThePreviewWritesNothingAndSaysWhatTheOwnPayerWouldHave(): void
    {
        $this->exec(new SetRetention(), ['event_id' => $this->event, 'retention' => 'nothing']);
        $this->pay('00A0', '3000');
        $this->payCongregation('00A0', '2000');
        $before = $this->counts();

        $preview = $this->withdraw('00A0', preview: true);

        // Only the registrant's own payer: never the congregation's 2 000.
        self::assertSame(
            [
                'allowed' => true,
                'paid' => '3000.00000000',
                'owed_after' => '-3000.00000000',
                'retained' => '0.00000000',
                'surplus' => '3000.00000000',
            ],
            $preview
        );
        self::assertSame($before, $this->counts());
        self::assertSame('registered', $this->status('00A0'));
    }

    public function testThePreviewRetainsEverythingPaidOfAPartialPaymentAndOwesNothingBack(): void
    {
        // The default rule keeps what was paid: of 3 000 paid towards the 8 000 fee, 3 000 stays, nothing is owed back.
        $this->pay('00A0', '3000');

        $preview = $this->withdraw('00A0', preview: true);

        self::assertSame(
            [
                'allowed' => true,
                'paid' => '3000.00000000',
                'owed_after' => '0.00000000',
                'retained' => '3000.00000000',
                'surplus' => '0.00000000',
            ],
            $preview
        );
    }

    public function testThePreviewOfARegistrationThatCannotWithdrawSaysNotAllowedAndWritesNothing(): void
    {
        $this->withdraw('00A0');
        $before = $this->counts();

        $preview = $this->withdraw('00A0', preview: true);

        self::assertSame(
            ['allowed' => false, 'paid' => null, 'owed_after' => null, 'retained' => null, 'surplus' => null],
            $preview
        );
        self::assertSame($before, $this->counts());
    }

    public function testThePreviewOfAnUnpaidRegistrationIsZero(): void
    {
        $preview = $this->withdraw('00A5', preview: true);

        self::assertSame(
            [
                'allowed' => true,
                'paid' => '0.00000000',
                'owed_after' => '0.00000000',
                'retained' => '0.00000000',
                'surplus' => '0.00000000',
            ],
            $preview
        );
    }

    public function testThePreviewStillNeedsTheActiveRegistrationAndItsCode(): void
    {
        try {
            $this->withdrawal()->run(null, '00A0', true);
            self::fail('No active registration.');
        } catch (WithdrawStale) {
            self::assertSame('registered', $this->status('00A0'));
        }
        $this->expectException(WithdrawStale::class);
        $this->withdrawal()->run($this->key('00A0'), '00A2', true);
    }

    public function testCanWithdrawFollowsTheStatusAndTheLoadState(): void
    {
        $read = new StatusRead();
        self::assertTrue($read->of($this->key('00A0'))?->can->withdraw);
        self::assertTrue($read->of($this->key('00A5'))?->can->withdraw);

        $this->withdraw('00A0');
        self::assertFalse($read->of($this->key('00A0'))?->can->withdraw);

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_registrations', ['status' => 'removed'], [
            'event_id' => $this->event,
            'code' => '00A2',
        ]);
        self::assertFalse($read->of($this->key('00A2'))?->can->withdraw);

        LoadState::record([new LoadFailure('x', 'y')]);
        self::assertFalse($read->of($this->key('00A5'))?->can->withdraw);
    }

    private function closesAt(?string $at): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_events',
            ['registration_closes_at' => $at === null ? null : $at . '.000000'],
            ['id' => $this->event]
        );
        // The write went behind the repository: drop what the row cache holds.
        \Kalaka\Foundation\ObjectCache::clear();
    }

    /** N661: the participant may withdraw only until the event's registration closes; after that, only staff. */
    public function testAfterTheRegistrationClosesOnlyStaffCanWithdraw(): void
    {
        // The clock is at 2026-12-01 10:00 site time (09:00 UTC); the closing instant itself is already closed.
        foreach (['2026-12-01 08:00:00', '2026-12-01 09:00:00'] as $closes) {
            $this->closesAt($closes);
            foreach (['00A0', '00A5'] as $code) {
                try {
                    $this->withdraw($code);
                    self::fail('Closed: ' . $code . ' ' . $closes);
                } catch (WithdrawClosed $e) {
                    self::assertSame('kalaka_withdraw_closed', $e->errorCode());
                    self::assertNotSame((new WithdrawUnavailable())->errorCode(), $e->errorCode());
                }
                self::assertNotSame('withdrawn', $this->status($code));
                self::assertSame(
                    ['allowed' => false, 'paid' => null, 'owed_after' => null, 'retained' => null, 'surplus' => null],
                    $this->withdraw($code, preview: true)
                );
            }
            self::assertSame('kalaka_withdraw_closed', WithdrawRefusalCookie::codeFor('kalaka_withdraw_closed'));
        }

        // Staff still can.
        $staff = new \Kalaka\Registrations\Status\WithdrawRegistration([]);
        $this->exec($staff, ['event_id' => $this->event, 'code' => '00A0']);
        self::assertSame('withdrawn', $this->status('00A0'));
    }

    public function testBeforeTheCloseAndWithoutOneTheParticipantCanWithdraw(): void
    {
        $this->closesAt('2026-12-01 09:00:01');
        $this->withdraw('00A0');
        self::assertSame('withdrawn', $this->status('00A0'));

        $this->closesAt(null);
        $this->withdraw('00A5');
        self::assertSame('withdrawn', $this->status('00A5'));
    }

    public function testTheStatusReadSaysWhyWithdrawalIsUnavailableAfterTheClose(): void
    {
        // A fresh read each time: a reader keeps the event it loaded.
        $read = static fn (): StatusRead => new StatusRead();
        $can = $read()->of($this->key('00A0'))?->can;
        self::assertTrue($can?->withdraw);
        self::assertNull($can->withdrawUnavailable);

        $this->closesAt('2026-12-01 08:59:59');
        $can = $read()->of($this->key('00A0'))?->can;
        self::assertFalse($can?->withdraw);
        self::assertSame('registration_closed', $can->withdrawUnavailable);
        self::assertFalse($read()->of($this->key('00A5'))?->can->withdraw);

        // A status that cannot withdraw anyway gives no closing reason.
        $this->closesAt('2026-12-15 23:00:00');
        $this->withdraw('00A0');
        $this->closesAt('2026-12-01 08:59:59');
        $can = $read()->of($this->key('00A0'))?->can;
        self::assertFalse($can?->withdraw);
        self::assertNull($can->withdrawUnavailable);
    }

    // --- the route ---

    private function post(string $contentType, string $body): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/kalaka-portal/v1/withdraw');
        $request->set_header('Content-Type', $contentType);
        $request->set_body($body);
        if ($contentType === 'application/json') {
            $request->set_body_params((array) json_decode($body, true));
        } else {
            parse_str($body, $params);
            $request->set_body_params($params);
        }

        return rest_do_request($request);
    }

    public function testAJsonPostWithdrawsTheActiveRegistration(): void
    {
        ActiveRegistrationState::set($this->key('00A5'), false);

        $response = $this->post('application/json', (string) wp_json_encode(
            ['code' => '00A5', Withdrawal::NONCE_FIELD => Withdrawal::nonceValue('00A5')]
        ));

        self::assertSame(200, $response->get_status());
        self::assertSame(['changed' => true, 'status' => 'withdrawn'], $response->get_data());
        self::assertSame('withdrawn', $this->status('00A5'));
        self::assertStringContainsString('no-store', (string) $response->get_headers()['Cache-Control']);
    }

    public function testAJsonPreviewThroughTheRouteWritesNothing(): void
    {
        ActiveRegistrationState::set($this->key('00A0'), false);
        $before = $this->counts();

        $response = $this->post('application/json', (string) wp_json_encode(['code' => '00A0', 'preview' => true]));

        self::assertSame(200, $response->get_status());
        self::assertSame(
            [
                'allowed' => true,
                'paid' => '0.00000000',
                'owed_after' => '0.00000000',
                'retained' => '0.00000000',
                'surplus' => '0.00000000',
            ],
            $response->get_data()
        );
        self::assertSame($before, $this->counts());
    }

    public function testAFormPostWithdrawsAndIsRedirectedCleanWithNoCookie(): void
    {
        ActiveRegistrationState::set($this->key('00A5'), false);

        $response = $this->post(
            'application/x-www-form-urlencoded',
            $this->formBody('00A5', 'return_url=' . rawurlencode(home_url('/statusz/?x=1')))
        );

        self::assertSame(303, $response->get_status());
        self::assertSame(home_url('/statusz/?x=1'), $response->get_headers()['Location']);
        self::assertArrayNotHasKey('Set-Cookie', $response->get_headers());
        self::assertSame('withdrawn', $this->status('00A5'));
    }

    public function testAFormPostIsNeverAPreview(): void
    {
        ActiveRegistrationState::set($this->key('00A5'), false);

        $this->post('application/x-www-form-urlencoded', $this->formBody('00A5', 'preview=true'));

        self::assertSame('withdrawn', $this->status('00A5'));
    }

    public function testARefusedFormPostGoesBackWithOnlyTheCodeInAFlashCookie(): void
    {
        ActiveRegistrationState::set($this->key('00A0'), false);

        $response = $this->post(
            'application/x-www-form-urlencoded',
            $this->formBody('00A2', 'return_url=' . rawurlencode('https://elsewhere.example.net/'))
        );

        self::assertSame(303, $response->get_status());
        self::assertSame(home_url('/'), $response->get_headers()['Location']);
        $cookie = (string) $response->get_headers()['Set-Cookie'];
        self::assertStringStartsWith(
            'kalaka_withdraw_refusal=kalaka_withdraw_stale; Max-Age=120; Path=/',
            $cookie
        );
        self::assertStringContainsString('HttpOnly; SameSite=Lax', $cookie);
        self::assertSame('registered', $this->status('00A2'));
        self::assertSame('registered', $this->status('00A0'));
    }

    public function testARefusedFormPostOfAStatusThatCannotWithdrawCarriesTheUnavailableCode(): void
    {
        $this->withdraw('00A0');
        ActiveRegistrationState::set($this->key('00A0'), false);

        $response = $this->post('application/x-www-form-urlencoded', $this->formBody('00A0'));

        self::assertSame(303, $response->get_status());
        self::assertStringStartsWith(
            'kalaka_withdraw_refusal=kalaka_withdraw_unavailable; Max-Age=120; Path=/',
            (string) $response->get_headers()['Set-Cookie']
        );
    }

    public function testAJsonPreviewWithAStaleCodeIsAnErrorNotNotAllowed(): void
    {
        $none = $this->post('application/json', (string) wp_json_encode(['code' => '00A0', 'preview' => true]));
        self::assertSame(422, $none->get_status());
        self::assertSame('kalaka_withdraw_stale', $none->get_data()['code'] ?? null);

        ActiveRegistrationState::set($this->key('00A0'), false);
        $other = $this->post('application/json', (string) wp_json_encode(['code' => '00A2', 'preview' => true]));
        self::assertSame('kalaka_withdraw_stale', $other->get_data()['code'] ?? null);
    }

    public function testAJsonRefusalCarriesTheErrorCodeAndNothingChanges(): void
    {
        $response = $this->post('application/json', (string) wp_json_encode(
            ['code' => '00A0', Withdrawal::NONCE_FIELD => Withdrawal::nonceValue('00A0')]
        ));

        self::assertSame(422, $response->get_status());
        self::assertSame('kalaka_withdraw_stale', $response->get_data()['code'] ?? null);
        self::assertSame('registered', $this->status('00A0'));

        ActiveRegistrationState::set($this->key('00A0'), false);
        $mismatch = $this->post('application/json', (string) wp_json_encode(
            ['code' => '00A2', Withdrawal::NONCE_FIELD => Withdrawal::nonceValue('00A2')]
        ));
        self::assertSame('kalaka_withdraw_stale', $mismatch->get_data()['code'] ?? null);

        $this->withdraw('00A0');
        $again = $this->post('application/json', (string) wp_json_encode(
            ['code' => '00A0', Withdrawal::NONCE_FIELD => Withdrawal::nonceValue('00A0')]
        ));
        self::assertSame('kalaka_withdraw_unavailable', $again->get_data()['code'] ?? null);
    }

    public function testAFormPostOnAClosedSiteIsRedirectedWithTheFlashCookie(): void
    {
        ActiveRegistrationState::set($this->key('00A5'), false);
        LoadState::record([new LoadFailure('x', 'y')]);

        $response = $this->post('application/x-www-form-urlencoded', $this->formBody('00A5'));
        LoadState::record([]);

        self::assertSame(303, $response->get_status());
        self::assertSame(home_url('/'), $response->get_headers()['Location']);
        self::assertStringStartsWith(
            'kalaka_withdraw_refusal=kalaka_site_closed; Max-Age=120; Path=/',
            (string) $response->get_headers()['Set-Cookie']
        );
        self::assertSame('waitlisted', $this->status('00A5'));
    }

    public function testAFormPostWithoutAReturnAddressGoesBackToASameSiteReferrer(): void
    {
        ActiveRegistrationState::set($this->key('00A5'), false);
        $_SERVER['HTTP_REFERER'] = home_url('/statusz/');
        try {
            $response = $this->post('application/x-www-form-urlencoded', $this->formBody('00A5'));
        } finally {
            unset($_SERVER['HTTP_REFERER']);
        }

        self::assertSame(303, $response->get_status());
        self::assertSame(home_url('/statusz/'), $response->get_headers()['Location']);
        self::assertSame('withdrawn', $this->status('00A5'));
    }

    public function testAClosedSiteRefusesTheRouteBeforeAnythingElse(): void
    {
        ActiveRegistrationState::set($this->key('00A5'), false);
        LoadState::record([new LoadFailure('x', 'y')]);

        $response = $this->post('application/json', (string) wp_json_encode(
            ['code' => '00A5', Withdrawal::NONCE_FIELD => Withdrawal::nonceValue('00A5')]
        ));

        self::assertSame(503, $response->get_status());
        self::assertSame((new SiteClosed())->errorCode(), $response->get_data()['code'] ?? null);
        LoadState::record([]);
        self::assertSame('waitlisted', $this->status('00A5'));
    }

    // --- the nonce (N1166) ---

    /** The field the page prints holds the nonce of that registration's code, and reading it back matches. */
    public function testThePrintedFieldHoldsTheCodesNonce(): void
    {
        $field = Withdrawal::nonceField('00A0');

        self::assertStringStartsWith('<input type="hidden" name="kalaka_withdraw_nonce" value="', $field);
        self::assertStringEndsWith('">', $field);
        preg_match('/value="([^"]+)"/', $field, $found);
        $nonce = html_entity_decode($found[1]);
        self::assertTrue(Withdrawal::nonceIsValid($nonce, '00A0'));
        self::assertSame(10, strlen($nonce));
        // The field is escaped, so nothing in it can leave the attribute.
        self::assertSame($field, Withdrawal::nonceField('00A0'));
    }

    public function testAGuestsNonceWithdraws(): void
    {
        // The participant is a guest: no WordPress user, only the signed cookie. A logged-out WordPress nonce would
        // be one value shared by every guest, so this one is bound to the code.
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id());
        ActiveRegistrationState::set($this->key('00A0'), false);

        $response = $this->post('application/x-www-form-urlencoded', $this->formBody('00A0'));

        self::assertSame(303, $response->get_status());
        self::assertArrayNotHasKey('Set-Cookie', $response->get_headers());
        self::assertSame('withdrawn', $this->status('00A0'));
    }

    public function testAMissingNonceIsRefusedWithTheBadNonceCode(): void
    {
        ActiveRegistrationState::set($this->key('00A0'), false);

        $response = $this->post('application/x-www-form-urlencoded', 'code=00A0');

        self::assertSame(303, $response->get_status());
        self::assertStringStartsWith(
            'kalaka_withdraw_refusal=kalaka_withdraw_bad_nonce; Max-Age=120; Path=/',
            (string) $response->get_headers()['Set-Cookie']
        );
        self::assertSame('registered', $this->status('00A0'));

        try {
            $this->withdrawal()->run($this->key('00A0'), '00A0');
            self::fail('A real withdrawal without a nonce is refused.');
        } catch (WithdrawBadNonce $e) {
            self::assertSame('kalaka_withdraw_bad_nonce', $e->errorCode());
        }
        self::assertSame('registered', $this->status('00A0'));
    }

    public function testAWrongNonceIsRefusedWithTheBadNonceCode(): void
    {
        ActiveRegistrationState::set($this->key('00A0'), false);

        $response = $this->post(
            'application/x-www-form-urlencoded',
            'code=00A0&' . Withdrawal::NONCE_FIELD . '=0000000000'
        );

        self::assertSame(303, $response->get_status());
        self::assertStringStartsWith(
            'kalaka_withdraw_refusal=kalaka_withdraw_bad_nonce;',
            (string) $response->get_headers()['Set-Cookie']
        );
        $this->expectException(WithdrawBadNonce::class);
        $this->withdrawal()->run($this->key('00A0'), '00A0', false, '0000000000');
    }

    public function testANonceOfAnotherRegistrationsCodeIsRefused(): void
    {
        // The page of 00A2's visitor: that nonce opens 00A2 and nothing else, even for someone holding 00A0.
        $nonce = Withdrawal::nonceValue('00A2');
        self::assertFalse(Withdrawal::nonceIsValid($nonce, '00A0'));

        ActiveRegistrationState::set($this->key('00A0'), false);
        $response = $this->post(
            'application/x-www-form-urlencoded',
            'code=00A0&' . Withdrawal::NONCE_FIELD . '=' . rawurlencode($nonce)
        );

        self::assertSame(303, $response->get_status());
        self::assertStringStartsWith(
            'kalaka_withdraw_refusal=kalaka_withdraw_bad_nonce;',
            (string) $response->get_headers()['Set-Cookie']
        );
        self::assertSame('registered', $this->status('00A0'));

        // And the reverse: 00A0's nonce does not withdraw 00A2, even with 00A2 active.
        ActiveRegistrationState::set($this->key('00A2'), false);
        $this->expectException(WithdrawBadNonce::class);
        $this->withdrawal()->run($this->key('00A2'), '00A2', false, Withdrawal::nonceValue('00A0'));
    }

    public function testANonceFromTheTickBeforeStillWorksAndAnOlderOneDoesNot(): void
    {
        // A page older than the current tick is still fine, like a WordPress nonce's second half; two ticks back is
        // outside the window. The tick is the site's clock's, so it is read here the same way.
        $code = '00A0';
        $tick = (int) wp_nonce_tick(Withdrawal::NONCE_ACTION);
        $hash = static fn (int $at): string => substr(
            wp_hash($at . '|' . Withdrawal::NONCE_ACTION . '|' . $code, 'nonce'),
            -12,
            10
        );
        self::assertTrue(Withdrawal::nonceIsValid($hash($tick - 1), $code));
        self::assertFalse(Withdrawal::nonceIsValid($hash($tick - 2), $code));
    }

    // --- the flash cookie ---

    public function testTheFlashCookieKeepsOnlyAKnownCodeAndIsReadOnce(): void
    {
        self::assertSame('kalaka_withdraw_unavailable', WithdrawRefusalCookie::codeFor('kalaka_withdraw_unavailable'));
        self::assertSame('kalaka_status_act_refused', WithdrawRefusalCookie::codeFor('kalaka_status_act_refused'));
        self::assertSame('kalaka_withdraw_stale', WithdrawRefusalCookie::codeFor('kalaka_withdraw_stale'));
        self::assertSame('kalaka_withdraw_bad_nonce', WithdrawRefusalCookie::codeFor('kalaka_withdraw_bad_nonce'));
        self::assertSame('kalaka_site_closed', WithdrawRefusalCookie::codeFor('kalaka_site_closed'));
        self::assertSame('kalaka_version_conflict', WithdrawRefusalCookie::codeFor('kalaka_version_conflict'));
        self::assertSame('kalaka_status_act_refused', WithdrawRefusalCookie::codeFor('kalaka_status_act_refused'));
        self::assertSame('kalaka_system_failure', WithdrawRefusalCookie::codeFor('anything <b>else</b>'));

        $response = new RecordingResponse();
        WithdrawRefusalCookie::read([WithdrawRefusalCookie::NAME => 'kalaka_withdraw_unavailable'], $response);
        self::assertSame('kalaka_withdraw_unavailable', WithdrawRefusalState::code());
        self::assertSame(['', -1], $response->cookies[WithdrawRefusalCookie::NAME]);

        WithdrawRefusalCookie::read([WithdrawRefusalCookie::NAME => '<script>'], new RecordingResponse());
        self::assertNull(WithdrawRefusalState::code());

        WithdrawRefusalCookie::read(
            [WithdrawRefusalCookie::NAME => 'kalaka_withdraw_unavailable'],
            new RecordingResponse()
        );
        $view = $this->flashed();
        self::assertSame('kalaka_withdraw_unavailable', $view);
    }

    private function flashed(): ?string
    {
        ActiveRegistrationState::set($this->key('00A0'), false);

        return (new StatusRead())->forActive()?->withdrawRefusal;
    }
}
