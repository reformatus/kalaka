<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/**
 * The administrator's first mail: a code-defined starter body the new-mail action can offer, so an event's
 * first body starts from a usable text instead of an empty editor. It is content, not a setting: nothing is
 * written to the database until an admin chooses it. [UNAPPROVED N1050]
 *
 * The body uses the mail context's declared keys only (`recipient.name`, `event.name`, `registration.code`,
 * `registration.link`). It deliberately holds no money placeholder: the money table and the transfer block
 * are not keys on main, and a body naming them would be refused at save. They join in the change that lands
 * those keys. The subject is plain text: the mail subject is the body post's title and is not value-rendered.
 * [UNAPPROVED T1000, T1001]
 */
final class StarterTemplate
{
    /** The subject; the body post's title, plain text. [UNAPPROVED T1000] */
    public const SUBJECT = 'Megkaptuk a jelentkezésedet';

    /** The starter body, as block markup with the engine's inline values. [UNAPPROVED T1001] */
    public static function body(): string
    {
        return <<<'HTML'
<!-- wp:paragraph -->
<p>Kedves <span data-kalaka-value="recipient.name">Jelentkező</span>!</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Megkaptuk a jelentkezésedet erre az eseményre:
<span data-kalaka-value="event.name">esemény</span>. A jelentkezésed azonosítója:
<strong><span data-kalaka-value="registration.code">azonosító</span></strong>.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>A jelentkezésed állapotát és a fizetendő összeget ezen a linken nézheted meg:
<span data-kalaka-value="registration.link">link</span></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Ha kérdésed van, erre a levélre válaszolva elérsz minket.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Üdvözlettel:<br>A szervezők</p>
<!-- /wp:paragraph -->
HTML;
    }
}
