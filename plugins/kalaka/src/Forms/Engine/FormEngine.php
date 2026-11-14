<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormAvailability;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormHost;
use Kalaka\Forms\Api\FormOutdated;
use Kalaka\Forms\Api\FormTerminal;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\Values;
use Kalaka\Forms\Engine\Condition\ChainMarks;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The form engine's reads and checks of a form, for one host (`Api\FormHost`): what `Api\Forms` gives
 * other modules, and what the form's page and its routes use. It reads WordPress posts, so it runs
 * before or after a use case's transaction, never inside it.
 */
final class FormEngine
{
    public function __construct(
        private readonly FormHost $host,
        private readonly FormPosts $posts = new FormPosts(),
        private readonly DefinitionParser $parser = new DefinitionParser(),
        private readonly AnswerValidator $validator = new AnswerValidator(),
        private readonly FormWalk $walk = new FormWalk(),
    ) {
    }

    /**
     * The published form with that ID, or null for anything else: another post type, a form that
     * is not published or whose event or placement setting is missing or invalid.
     *
     * @throws SystemFailure when the form has no revision
     */
    public function form(int $id): ?Form
    {
        return $this->posts->form($id);
    }

    /**
     * The definition of the form's current version, or of an earlier revision of the same form. The current
     * version must also agree with the keys beyond the form (`FormHost::keyProblem()`: in an event form, a
     * key of another type, or a `system` field's key, makes it unusable): a form written around the save
     * check stays closed and is named in the broken-form warning. An earlier version is read as it was.
     *
     * @throws SystemFailure when the version is not a revision of the form or the definition has a problem
     */
    public function definition(Form $form, ?int $version = null): FormDefinition
    {
        $version ??= $form->version;
        $content = $this->posts->content($form, $version);
        $definition = $this->parser->parse($form, $version, $content, Contributions::current(), $this->host);
        if ($version === $form->version) {
            $problem = $this->host->keyProblem($form, $content);
            if ($problem !== null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure($problem[0] . ' ' . $problem[1]);
            }
        }

        return $definition;
    }

    /**
     * The definition of the version a form page was built from, which must be the form's latest version
     * (S76 follow-up, N8): a re-fetch and a submit accept nothing else.
     *
     * @throws FormOutdated when the version is not the latest
     * @throws SystemFailure when the definition has a problem
     */
    public function latestDefinition(Form $form, int $version): FormDefinition
    {
        if ($version !== $form->version) {
            $error = new FormOutdated();
            throw $error;
        }

        return $this->definition($form, $version);
    }

    /**
     * The parsed blocks of the form's version with every branch block marked (`Api\Forms::blocks()`).
     *
     * @return list<array<string, mixed>>
     * @throws SystemFailure when the version is not a revision of the form, or a branch is misplaced
     */
    public function blocks(Form $form, ?int $version = null, ?FormAvailability $availability = null): array
    {
        $version ??= $form->version;
        $marked = ChainMarks::mark(array_values(parse_blocks($this->posts->content($form, $version))));
        $withheld = [];
        foreach ($this->definition($form, $version)->withheldChains() as $number) {
            $withheld[$number] = $availability?->heldBranch($number);
        }

        // The after-submit section is never rendered with the form.
        return array_values(array_filter(
            ChainMarks::prune($marked, $withheld),
            static fn (array $block): bool => ($block['blockName'] ?? null) !== ChainMarks::AFTER_SUBMIT_BLOCK
        ));
    }

    /**
     * The after-submit section of the form's version rendered with the context (`Api\Forms::afterSubmit()`).
     *
     * @throws SystemFailure when the version is not a revision of the form, or a key the context lacks
     */
    public function afterSubmit(Form $form, int $version, ValueContext $context): ?string
    {
        $section = self::findAfterSubmit(parse_blocks($this->posts->content($form, $version)));
        if ($section === null) {
            return null;
        }
        $inner = is_array($section['innerBlocks'] ?? null) ? array_values($section['innerBlocks']) : [];

        return (new Values())->render(serialize_blocks($inner), $context);
    }

    /**
     * @param array<array-key, mixed> $blocks
     * @return array<string, mixed>|null
     */
    private static function findAfterSubmit(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['blockName'] ?? null) === ChainMarks::AFTER_SUBMIT_BLOCK) {
                /** @var array<string, mixed> $block */
                return $block;
            }
            $found = self::findAfterSubmit(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : []);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The submit's check of the answers (`Api\Forms::validate()`).
     *
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws InvalidAnswers missing or wrong answers
     * @throws FormTerminal a terminal page shows for the answers
     * @throws SystemFailure a key the facts do not declare
     */
    public function validate(FormDefinition $definition, array $answers, ?Facts $facts = null): ValidatedAnswers
    {
        return $this->validator->validate($definition, $answers, Contributions::current(), $facts);
    }

    /**
     * What the form shows for answers as they stand (`Api\Forms::availability()`).
     *
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type, a page out of range
     * @throws SystemFailure a key the facts do not declare
     */
    public function availability(
        FormDefinition $definition,
        array $answers,
        Facts $facts,
        ?int $page = null
    ): FormAvailability {
        if ($page !== null && !isset($definition->pages[$page])) {
            // a page out of range, or any page of a form without pages.
            $error = new InvalidInput();
            throw $error;
        }
        $result = $this->walk->run($definition, $answers, $facts, Contributions::current());
        $errors = [];
        if ($page !== null && ($result->pages[$page] ?? false) && !$definition->pages[$page]->terminal) {
            foreach ($result->errors as $key => $message) {
                if ($definition->field($key)?->page === $page) {
                    $errors[$key] = $message;
                }
            }
        }

        return new FormAvailability(
            $result->shown,
            $result->held,
            $result->options,
            $definition->triggers,
            $definition->withheldChains(),
            $result->pages,
            $errors
        );
    }
}
