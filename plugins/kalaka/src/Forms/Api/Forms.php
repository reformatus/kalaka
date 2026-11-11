<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\AnswerValidator;
use Kalaka\Forms\Condition\ChainMarks;
use Kalaka\Forms\DefinitionParser;
use Kalaka\Forms\FormPosts;
use Kalaka\Forms\ParticipantField\FieldBlocks;
use Kalaka\Forms\ParticipantField\ParticipantFieldQuery;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Forms\FormWalk;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Reads forms and checks answers against them. It reads WordPress posts, so it runs before or
 * after a use case's transaction, never inside it: a submit reads the form and its definition
 * first and passes them in.
 */
final class Forms
{
    public function __construct(
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
     * version must also agree with the event's participant fields (a key of another type, or a `system`
     * field's key, makes it unusable): a form written around the save check stays closed and is named in
     * the broken-form warning. An earlier version is read as it was.
     *
     * @throws SystemFailure when the version is not a revision of the form or the definition has a problem
     */
    public function definition(Form $form, ?int $version = null): FormDefinition
    {
        $version ??= $form->version;
        $content = $this->posts->content($form, $version);
        $definition = $this->parser->parse($form, $version, $content, Contributions::current());
        if ($version === $form->version) {
            $problem = SharedKeyCheck::problem($content, $form->eventId, Contributions::current());
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
     * The parsed blocks of the form's version with every branch block marked with its chain number and
     * branch index (`Condition\ChainMarks`, the numbering the definition reads): what a form page renders,
     * block by block with `render_block()`, outside `Values::render()`.
     *
     * An answer-driven chain ships every branch, each as `<div data-kalaka-chain="N" data-kalaka-branch="i"
     * hidden>…</div>` (`BranchMarkup`); the page shows the one `FormAvailability::$chains` holds. A
     * withheld chain (`FormDefinition::withheldChains()`: it reads an event value or a held fact) ships
     * only the branch the availability holds, without `hidden`, and nothing of the others.
     * [UNAPPROVED N11]
     *
     * @param FormAvailability|null $availability what shows for the page's answers; without it no branch
     *     of a withheld chain ships
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

        return ChainMarks::prune($marked, $withheld);
    }

    /**
     * The after-submit section of the form's version (`AfterSubmit`, N12) rendered with the context
     * (`AfterSubmit::context()`): its inner blocks through `Values::render()`, conditions resolved, values
     * filled. Null when the form has none; the caller then shows its default text.
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
            if (($block['blockName'] ?? null) === AfterSubmit::BLOCK) {
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
     * What keeps the form from being used, in this order: the event is not open (returned
     * alone), the registration slot's date window, the password, the required role. Pure: the
     * caller passes the moment, the user's roles and whether the password was given.
     *
     * @param list<string> $roles
     * @return list<AccessRefusal>
     */
    public function refusals(
        Form $form,
        Event $event,
        \DateTimeImmutable $now,
        array $roles,
        bool $passwordGiven
    ): array {
        if ($event->state() !== EventState::Published) {
            return [AccessRefusal::EventNotOpen];
        }

        $refusals = [];
        if ($form->placement === FormPlacement::Registration && !$event->registrationWindowContains($now)) {
            $refusals[] = AccessRefusal::OutsideRegistrationWindow;
        }
        if ($form->hasPassword && !$passwordGiven) {
            $refusals[] = AccessRefusal::PasswordRequired;
        }
        if ($form->requiredRole !== null && !in_array($form->requiredRole, $roles, true)) {
            $refusals[] = AccessRefusal::RoleRequired;
        }

        return $refusals;
    }

    /**
     * The event's participant fields, in the order they were declared (`position`); retired ones only when
     * asked. Each names the forms of the event that hold it now and its type's label. It reads Kaláka's own
     * table and the event's form posts, so it runs outside a use case's transaction.
     *
     * @return list<ParticipantField>
     */
    public function participantFields(int $eventId, bool $includeRetired = false): array
    {
        $holders = [];
        foreach ($this->posts->ofEvent($eventId) as $post) {
            foreach (array_keys(FieldBlocks::of($post->post_content, Contributions::current())) as $key) {
                $holders[$key][] = ['form_id' => $post->ID, 'title' => $post->post_title];
            }
        }
        $blocks = \WP_Block_Type_Registry::get_instance();

        $fields = [];
        foreach ((new ParticipantFieldQuery())->forEvent($eventId) as $record) {
            if ($record->retiredAt !== null && !$includeRetired) {
                continue;
            }
            $options = [];
            foreach ($record->options ?? [] as $option) {
                $options[] = [
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'retired' => $option['retired_at'] !== null,
                ];
            }
            $title = $blocks->get_registered($record->type)?->title;
            $fields[] = new ParticipantField(
                $record->key,
                $record->type,
                $record->title,
                $options,
                $record->source,
                $record->retiredAt !== null,
                is_string($title) && $title !== '' ? $title : $record->type,
                $record->writableFromForms(),
                $holders[$record->key] ?? [],
            );
        }

        return $fields;
    }

    /**
     * The submit's check of the answers, with the same walk as `availability()`.
     * Decision N5 (#156): the facts are optional, so the signature of #53 stays.
     *
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @param Facts|null $facts the facts besides the answers: the event's keys (`EventValues::of()`) for a
     *     form whose conditions read them; none when null
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws InvalidAnswers missing or wrong answers
     * @throws SystemFailure a key the facts do not declare
     */
    public function validate(FormDefinition $definition, array $answers, ?Facts $facts = null): ValidatedAnswers
    {
        return $this->validator->validate($definition, $answers, Contributions::current(), $facts);
    }

    /**
     * What the form shows for answers as they stand (a draft, "if submitted now"; S76): the read model
     * every render and re-render of the form's page builds from. Pure; errors in the answers are not
     * reported here, an answer in error reads as empty.
     *
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @param Facts $facts the facts besides the answers: the event's keys (`EventValues::of()`)
     * @throws InvalidInput an undeclared key or a value of the wrong JSON type
     * @throws SystemFailure a key the facts do not declare
     */
    public function availability(FormDefinition $definition, array $answers, Facts $facts): FormAvailability
    {
        $result = $this->walk->run($definition, $answers, $facts, Contributions::current());

        return new FormAvailability(
            $result->shown,
            $result->held,
            $result->options,
            $definition->triggers,
            $definition->withheldChains()
        );
    }
}
