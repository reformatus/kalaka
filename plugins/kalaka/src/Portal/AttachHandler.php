<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Registrations\Api\AttachLinks;
use Kalaka\Registrations\Api\RegistrationAttach;

/**
 * The attach mail's link (S15, [UNAPPROVED]), handled at `init` like the secret link: a GET carrying
 * `kalaka_attach` is never cached and always ends in a 302.
 *
 * - Not logged in: to WordPress's login page, which returns to the same URL, link and all.
 * - Logged in: `kalaka-portal/attach-registration` runs as the account, then the 302 goes to the same URL
 *   without the link. A link for another account, or one that is not current, attaches nothing; nothing is
 *   shown either way (the account area is "coming soon").
 */
final class AttachHandler
{
    public function __construct(
        private readonly Response $response = new HttpResponse(),
        private readonly RegistrationAttach $attach = new RegistrationAttach(),
    ) {
    }

    /**
     * @param array<string, mixed> $query the request's query parameters, unslashed
     * @param string $url the request's URL (path and query)
     * @param int $userId the logged-in user; 0 for none
     */
    public function handle(array $query, string $method, string $url, int $userId): void
    {
        $link = $query[AttachLinks::PARAMETER] ?? null;
        if ($link === null || !in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }
        $this->response->noCache();
        if ($userId < 1) {
            $this->response->redirect(wp_login_url($url));

            return;
        }
        if (is_string($link) && LoadState::current()->isOpen()) {
            try {
                $this->attach->attach($link, $userId);
            } catch (PermissionDenied | NotFound) {
                // Another account's link, an old one, or a registration that is gone: nothing to attach.
            } catch (\Throwable $error) {
                Boundary::report('attach link', $error);
            }
        }
        $this->response->redirect(remove_query_arg(AttachLinks::PARAMETER, $url));
    }
}
