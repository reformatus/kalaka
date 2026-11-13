<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/**
 * A published form whose current version does not parse (saved before the save check existed, or by a
 * write around it) stays closed: `Forms::definition()` refuses it, so the form page shows the failure
 * text in place of the form, and no answer is taken. This warning names each such form, so a full-access
 * user fixes it in the editor. It reads the list `BrokenForms` keeps.
 */
final class BrokenFormsWarning implements SiteWarning
{
    private const TITLE = 'Hibás közzétett űrlap';

    private const DESCRIPTION = 'Egy vagy több közzétett űrlap hibás, ezért nem tölthető ki. '
        . 'Nyisd meg őket a szerkesztőben, javítsd a hibát, majd mentsd újra.';

    public function id(): string
    {
        return 'broken_forms';
    }

    /** The details name each form: `#<ID> <title>: <problem> <subject>`. Reads the stored list only. */
    public function check(): ?Shown
    {
        $broken = BrokenForms::stored();

        return $broken === []
            ? null
            : new Shown(Severity::Error, self::TITLE, self::DESCRIPTION, implode("\n", $broken));
    }

    public function siteHealth(): ?SiteHealth
    {
        return null;
    }
}
