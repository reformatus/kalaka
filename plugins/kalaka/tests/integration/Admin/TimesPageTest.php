<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Event\TimesPage;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Tests\Integration\Events\EventsTestCase;

/** The event's „Esemény adatai” page: the two points, rendered on the server, saved through UpdateEvent. */
final class TimesPageTest extends EventsTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user($this->admin->userId);
        // The page and its save run the production runner; it joins the harness's open transaction here.
        $this->production = Runner::current();
        Runner::publish($this->runner);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->production);
        wp_set_current_user(0);
        unset($_GET['page']);

        parent::tear_down();
    }

    private function page(int $event): string
    {
        $_GET['page'] = 'kalaka-event-' . $event . '-times';
        ob_start();
        TimesPage::render();

        return (string) ob_get_clean();
    }

    public function testTheFormShowsTheCurrentStartAndEndWithHours(): void
    {
        $event = $this->filled();
        $html = $this->page($event);

        self::assertStringContainsString('Kezdés', $html);
        self::assertStringContainsString('Befejezés', $html);
        self::assertStringContainsString('value="2027-07-05T10:00"', $html);
        self::assertStringContainsString('value="2027-07-12T14:00"', $html);
        self::assertStringContainsString('2027. július 5. 10:00', $html);
        self::assertStringContainsString('2027. július 12. 14:00', $html);
        self::assertStringContainsString('type="datetime-local"', $html);
    }

    public function testSavingOnlyTheTwoPointsKeepsTheRest(): void
    {
        $event = $this->filled();
        $read = (new Events())->find($event);
        self::assertNotNull($read);

        TimesPage::apply($read, '2027-07-06 11:30', '2027-07-08 09:00', $read->version);

        $row = $this->row($event);
        self::assertSame('2027-07-06 11:30', $this->localText((string) $row['starts_at']));
        self::assertSame('2027-07-08 09:00', $this->localText((string) $row['ends_at']));
        self::assertSame('Tábor', $row['name']);
        self::assertSame(41, (int) $row['capacity']);
        self::assertSame('2026-11-30 08:00', $this->localText((string) $row['registration_opens_at']));
    }

    public function testAUserWithoutFullAccessDoesNotSeeTheForm(): void
    {
        $event = $this->filled();
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $html = $this->page($event);

        self::assertStringNotContainsString('datetime-local', $html);
        self::assertStringNotContainsString('Kezdés', $html);
    }

    private function localText(string $stored): string
    {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))
            ->setTimezone(wp_timezone())
            ->format('Y-m-d H:i');
    }
}
