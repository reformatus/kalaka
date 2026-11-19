<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/** The base of the detail read's tests: an event, a registration-slot form and helpers that seed its data. */
abstract class RegistrationDetailTestCase extends FormsTestCase
{
    protected int $event;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->event = $this->create();
    }

    /**
     * The read of one registration for a user, with the declarations the test names.
     *
     * @return array<string, mixed>
     */
    protected function detail(int $user, string $code = '00A0', ?Decisions $decisions = null, int $event = 0): array
    {
        return (new RegistrationDetail(decisions: $decisions ?? new Decisions()))
            ->of(new UserActor($user), $event === 0 ? $this->event : $event, $code);
    }

    protected function addRegistration(
        string $code,
        string $status = 'registered',
        string $createdAt = '2026-11-30 08:00:00',
        int $event = 0,
    ): void {
        $this->insert('kalaka_registrations', [
            'event_id' => $event === 0 ? $this->event : $event,
            'code' => $code,
            'version' => 1,
            'status' => $status,
            'secret' => 'secret-of-' . $code,
            'created_at' => $createdAt . '.000000',
            'registered_at' => $status === 'registered' ? $createdAt . '.000000' : null,
        ]);
    }

    protected function addValue(string $code, string $key, string $value, int $version = 1): void
    {
        $this->insert('kalaka_field_values', [
            'event_id' => $this->event,
            'code' => $code,
            'field_key' => $key,
            'version' => $version,
            'value' => $value,
        ]);
    }

    protected function addSubmission(string $code, int $formId, int $version, string $at = '2026-11-30 08:00:00'): void
    {
        $this->insert('kalaka_submissions', [
            'event_id' => $this->event,
            'code' => $code,
            'number' => 1,
            'form_id' => $formId,
            'form_version' => $version,
            'answers' => '{}',
            'submitted_at' => $at . '.000000',
            'submit_token' => null,
            'answers_hash' => null,
        ]);
    }

    protected function addDecision(string $code, string $kind, string $state): void
    {
        $this->insert('kalaka_decisions', [
            'event_id' => $this->event,
            'code' => $code,
            'kind' => $kind,
            'state' => $state,
            'effective_at' => '2026-11-30 08:00:00.000000',
            'recorded_at' => '2026-11-30 08:00:00.000000',
            'cause_type' => 'test_record',
            'cause_event_id' => $this->event,
            'cause_key' => '1',
            'actor_type' => 'system',
        ]);
    }

    /**
     * Appends one ledger line; the times default to a manual payment's.
     *
     * @param array<string, int|string|null> $overrides
     */
    protected function addLine(
        string $kind,
        string $amount,
        string $key,
        string $payer = 'participant',
        array $overrides = [],
    ): int {
        $this->insert('kalaka_ledger_lines', $overrides + [
            'event_id' => $this->event,
            'code' => '00A0',
            'kind' => $kind,
            'payer' => $payer,
            'amount' => $amount,
            'currency' => 'HUF',
            'source_type' => 'system',
            'source_event_id' => null,
            'source_key' => 'system',
            'idempotency_key' => $key,
            'value_date' => '2026-12-02 23:00:00.000000',
            'paid_at' => null,
            'recorded_at' => '2026-11-30 08:00:00.000000',
            'method' => 'bank_transfer',
            'correction' => 0,
        ]);

        global $wpdb;

        return (int) $wpdb->insert_id;
    }

    protected function addNote(int $lineId, string $note): void
    {
        $this->insert('kalaka_payment_notes', [
            'event_id' => $this->event,
            'line_id' => $lineId,
            'code' => '00A0',
            'version' => 1,
            'note' => $note,
        ]);
    }

    /** A registration-slot form for the event, with its latest version. */
    protected function detailForm(string $more = ''): \Kalaka\Forms\Api\Form
    {
        $id = $this->insertChecked(
            $this->field('name', 'name', ['label' => 'Név', 'displayName' => true])
            . $this->field('email', 'email', ['label' => 'E-mail-cím', 'contactEmail' => true])
            . $more,
            ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'registration']
        );

        return $this->readForm($id);
    }

    /**
     * A view of the event and a grant of the actions on it.
     *
     * @param list<array<string, mixed>> $filter
     * @param list<array<string, mixed>> $columns
     * @param list<string> $actions
     * @return int the grant's id
     */
    protected function grant(int $user, array $filter, array $columns, array $actions, int $event = 0): int
    {
        $event = $event === 0 ? $this->event : $event;
        $view = $this->exec(new SaveView(), [
            'event_id' => $event,
            'name' => 'Nézet',
            'filter' => $filter,
            'columns' => $columns,
        ]);
        self::assertIsInt($view);
        $grant = $this->exec(new SaveGrant(), [
            'event_id' => $event,
            'user_id' => $user,
            'view_id' => $view,
            'bound_value' => null,
            'actions' => $actions,
        ]);
        self::assertIsInt($grant);

        return $grant;
    }

    /** A user without full access. */
    protected function plainUser(): int
    {
        return self::factory()->user->create(['role' => 'editor']);
    }

    /** A user whose display name is the given one. */
    protected function namedUser(string $displayName): int
    {
        return self::factory()->user->create(['role' => 'editor', 'display_name' => $displayName]);
    }

    /** @param array<string, int|string|null> $row */
    private function insert(string $table, array $row): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . $table, $row);
        DatabaseError::check($wpdb);
    }
}
