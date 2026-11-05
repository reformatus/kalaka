<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\SecretLink;
use Kalaka\Registrations\Submission\SubmissionRepository;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Foundation\AuditTestCase;

require_once __DIR__ . '/../Foundation/AuditTestCase.php';

/** The base of the Registrations tests: the repositories, a runner in savepoint mode, helpers to seed rows. */
abstract class RegistrationsTestCase extends AuditTestCase
{
    protected RegistrationRepository $registrations;

    protected FieldValueRepository $values;

    protected SubmissionRepository $submissions;

    public function set_up(): void
    {
        parent::set_up();

        ObjectCache::clear();
        $this->registrations = new RegistrationRepository();
        $this->values = new FieldValueRepository();
        $this->submissions = new SubmissionRepository();
    }

    public function tear_down(): void
    {
        ObjectCache::clear();

        parent::tear_down();
    }

    /** Runs the work as a use case of its own and returns what it returned. */
    protected function inRun(\Closure $work): mixed
    {
        return $this->runner->run(new AdminProbe($work), [], new CliActor(null));
    }

    protected function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    protected function newRegistration(
        int $event,
        string $code,
        RegistrationStatus $status = RegistrationStatus::Registered,
        string $createdAt = '2026-11-30 08:00:00',
    ): Registration {
        $created = $this->utc($createdAt);

        return new Registration(
            new RegistrationKey($event, $code),
            $status,
            SecretLink::generate(),
            $created,
            $status === RegistrationStatus::Registered ? $created : null
        );
    }

    protected function insertRegistration(
        Registration $registration,
        ?Context $context = null,
    ): Registration {
        if ($context !== null) {
            return $this->registrations->upsert($registration, $context);
        }

        $stored = $this->inRun(fn (array $input, Context $run): Registration => $this->registrations->upsert($registration, $run));
        self::assertInstanceOf(Registration::class, $stored);

        return $stored;
    }

    /** Changes the status of a stored registration in a run of its own. */
    protected function setStatus(RegistrationKey $key, RegistrationStatus $status): void
    {
        $this->inRun(function (array $input, Context $context) use ($key, $status): void {
            $registration = $this->registrations->findForUpdate($key, $context);
            self::assertInstanceOf(Registration::class, $registration);
            $registration->status = $status;
            if ($status === RegistrationStatus::Registered) {
                $registration->registeredAt = $context->now();
            }
            $this->registrations->upsert($registration, $context);
        });
    }

    /**
     * Writes registration rows behind the repository, several to a statement.
     *
     * @param list<string> $codes
     */
    protected function seedCodes(int $event, array $codes): void
    {
        global $wpdb;

        foreach (array_chunk($codes, 1000) as $chunk) {
            $values = [];
            foreach ($chunk as $code) {
                $values[] = $wpdb->prepare("(%d, %s, 1, 'registered', 'secret', '2026-11-30 08:00:00.000000')", $event, $code);
            }
            $wpdb->query(
                "INSERT INTO {$wpdb->prefix}kalaka_registrations (event_id, code, version, status, secret, created_at) VALUES "
                . implode(', ', $values)
            );
        }
    }

    protected function registrationCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations");
    }

    /** @return array<string, ?string>|null */
    protected function rowOf(string $table, int $event, string $code): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$table} WHERE event_id = %d AND code = %s", $event, $code),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /** @return list<string> the statements the database receives while the work runs */
    protected function statementsDuring(\Closure $work): array
    {
        $seen = [];
        $capture = static function (string $query) use (&$seen): string {
            $seen[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        try {
            $work();
        } finally {
            remove_filter('query', $capture);
        }

        return $seen;
    }
}
