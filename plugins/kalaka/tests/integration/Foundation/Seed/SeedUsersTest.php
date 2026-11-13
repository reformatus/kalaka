<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Seed;

use Kalaka\Foundation\Seed\SeedUsers;

/** AC1. */
final class SeedUsersTest extends SeedTestCase
{
    private const EXPECTED = [
        'administrator' => 'Teszt Adminisztrátor',
        'editor' => 'Teszt Szerkesztő',
        'author' => 'Teszt Szerző',
        'contributor' => 'Teszt Közreműködő',
        'subscriber' => 'Teszt Feliratkozó',
    ];

    public function testEveryRoleGetsAUserWithItsNameEmailAndPassword(): void
    {
        $reported = [];
        (new SeedUsers())->run(static function (string $line) use (&$reported): void {
            $reported[] = $line;
        });

        self::assertCount(6, $reported);
        foreach (self::EXPECTED as $login => $displayName) {
            $user = get_user_by('login', $login);
            self::assertInstanceOf(\WP_User::class, $user);
            self::assertSame([$login], $user->roles);
            self::assertSame($displayName, $user->display_name);
            self::assertSame($login . '@example.org', $user->user_email);
            self::assertTrue(wp_check_password('password', $user->user_pass));
            self::assertFalse($user->has_cap('kalaka_full_access'));
        }
        $full = get_user_by('login', 'kalaka_admin');
        self::assertInstanceOf(\WP_User::class, $full);
        self::assertSame(['administrator'], $full->roles);
        self::assertSame('Teszt Kaláka-adminisztrátor', $full->display_name);
        self::assertSame('kalaka_admin@example.org', $full->user_email);
        self::assertTrue(wp_check_password('password', $full->user_pass));
        self::assertTrue($full->has_cap('kalaka_full_access'));
    }

    public function testAnExistingLoginIsLeftUnchangedAndTheOthersAreCreated(): void
    {
        self::factory()->user->create([
            'user_login' => 'editor',
            'user_email' => 'someone@example.org',
            'display_name' => 'Valaki Más',
            'role' => 'editor',
        ]);

        $reported = [];
        (new SeedUsers())->run(static function (string $line) use (&$reported): void {
            $reported[] = $line;
        });

        $editor = get_user_by('login', 'editor');
        self::assertInstanceOf(\WP_User::class, $editor);
        self::assertSame('Valaki Más', $editor->display_name);
        self::assertSame('someone@example.org', $editor->user_email);
        self::assertCount(6, $reported);
        self::assertSame(
            ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'kalaka_admin'],
            $this->seededLogins()
        );
        foreach (['administrator', 'author', 'contributor', 'subscriber'] as $login) {
            $user = get_user_by('login', $login);
            self::assertInstanceOf(\WP_User::class, $user);
            self::assertSame(self::EXPECTED[$login], $user->display_name);
        }
    }
}
