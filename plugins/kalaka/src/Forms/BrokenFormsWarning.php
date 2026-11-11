<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/**
 * A published form whose current version does not parse (saved before the save check existed, or by a
 * write around it) stays closed: `Forms::definition()` refuses it, so the form page shows the failure
 * text in place of the form, and no answer is taken. This warning names each such form, so a full-access
 * user fixes it in the editor. Read once per request.
 */
final class BrokenFormsWarning implements SiteWarning
{
    /** [UNAPPROVED N15] */
    private const TITLE = 'Hibás közzétett űrlap';

    /** [UNAPPROVED N15] */
    private const DESCRIPTION = 'Egy vagy több közzétett űrlap hibás, ezért nem tölthető ki. '
        . 'Nyisd meg őket a szerkesztőben, javítsd a hibát, majd mentsd újra.';

    /** @var list<string>|null one line per broken form, for this request */
    private ?array $broken = null;

    public function id(): string
    {
        return 'broken_forms';
    }

    /** The details name each form: `#<ID> <title>: <problem> <subject>`. */
    public function check(): ?Shown
    {
        $this->broken ??= $this->brokenForms();

        return $this->broken === []
            ? null
            : new Shown(Severity::Error, self::TITLE, self::DESCRIPTION, implode("\n", $this->broken));
    }

    public function siteHealth(): ?SiteHealth
    {
        return null;
    }

    /** @return list<string> */
    private function brokenForms(): array
    {
        $forms = new Forms();
        $ids = get_posts([
            'post_type' => FormPosts::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        $broken = [];
        foreach ($ids as $id) {
            try {
                $form = $forms->form($id);
                if ($form !== null) {
                    $forms->definition($form);
                }
            } catch (SystemFailure $e) {
                [$code, $subject] = DefinitionCheck::split($e);
                $broken[] = '#' . $id . ' ' . get_the_title($id) . ': ' . trim($code . ' ' . $subject);
            }
        }

        return $broken;
    }
}
