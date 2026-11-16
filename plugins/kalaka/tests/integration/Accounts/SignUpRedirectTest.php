<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\SignUpRedirect;

/** S15: the redirect to WordPress's registration page from a page that places a form needing an account. */
final class SignUpRedirectTest extends AccountsTestCase
{
    private const RETURN = 'https://example.org/jelentkezes/';

    private function page(string $content): \WP_Post
    {
        $post = get_post(self::factory()->post->create(['post_type' => 'page', 'post_content' => $content]));
        self::assertInstanceOf(\WP_Post::class, $post);

        return $post;
    }

    private static function formBlock(int $form): string
    {
        return '<!-- wp:kalaka/form {"formId":' . $form . '} /-->';
    }

    public function testTheFormsOfAPageAreFoundAsBlocksNestedOrNotAndAsShortcodes(): void
    {
        $content = '<!-- wp:group --><div>' . self::formBlock(7) . '</div><!-- /wp:group -->'
            . '[kalaka_form id="9"] [kalaka_form id="7"] [kalaka_form]';

        self::assertSame([7, 9], SignUpRedirect::formIds($content));
    }

    public function testSomeoneNotLoggedInIsSentToSignUpAndBack(): void
    {
        $form = $this->openForm('mandatory');

        $target = (new SignUpRedirect())->target($this->page(self::formBlock($form)), false, [], self::RETURN);

        self::assertSame(SignUp::url($form, self::RETURN), $target);
        self::assertStringContainsString('wp-login.php?action=register', (string) $target);
        parse_str((string) wp_parse_url((string) $target, PHP_URL_QUERY), $query);
        self::assertSame((string) $form, $query[SignUp::MARKER]);
        self::assertSame(self::RETURN, $query['redirect_to']);
    }

    public function testTheShortcodeIsSeenToo(): void
    {
        $form = $this->openForm('optional');

        $target = (new SignUpRedirect())->target(
            $this->page('[kalaka_form id="' . $form . '"]'),
            false,
            [],
            self::RETURN
        );

        self::assertNotNull($target);
    }

    public function testNoRedirectLoggedInInOffModeForAnAdditionalFormOrAfterTheSkip(): void
    {
        $mandatory = $this->openForm('mandatory');
        $off = $this->openForm('off');
        $additional = $this->openForm('mandatory', 'additional');
        $optional = $this->openForm('optional');
        $redirect = new SignUpRedirect();

        self::assertNull($redirect->target($this->page(self::formBlock($mandatory)), true, [], self::RETURN));
        self::assertNull($redirect->target($this->page(self::formBlock($off)), false, [], self::RETURN));
        self::assertNull($redirect->target($this->page(self::formBlock($additional)), false, [], self::RETURN));
        self::assertNull($redirect->target(
            $this->page(self::formBlock($optional)),
            false,
            [SignUp::SKIP => (string) $optional],
            self::RETURN
        ));
        // The skip marker does not skip a mandatory form.
        self::assertNotNull($redirect->target(
            $this->page(self::formBlock($mandatory)),
            false,
            [SignUp::SKIP => (string) $mandatory],
            self::RETURN
        ));
    }
}
