<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Checks a form's definition when it is saved as published, so a published form never goes from working
 * to broken through an edit. The check is the one `DefinitionParser` runs on the live form (keys,
 * options, unknown blocks, the slot's markings, and whatever the parser checks later on), over the
 * content as it will be stored (keys and option values derived first). A draft is not checked: it can be
 * half built. A form whose placement is not set yet is not checked either: it cannot be used anyway.
 *
 * The REST save (the block editor) is refused with a 400 before anything is written; any other save path
 * (code, WP-CLI) throws `InvalidInput` from `wp_insert_post_data`. An autosave is stored as a revision
 * and is never a version (`FormPosts`), so it is not checked.
 */
final class DefinitionCheck
{
    /** The refusal: `{problem}` is the problem code, `{subject}` the field key or block. */
    public const MESSAGE = 'Az űrlapot így nem lehet menteni: %1$s („%2$s”).';

    /**
     * The problem of a content as a published form of that placement, or null when it has none.
     *
     * @return array{string, string}|null the problem code and its subject (a key, a block or the form)
     */
    public static function problem(string $content, int $formId, FormPlacement $placement): ?array
    {
        $contributions = Contributions::current();
        $content = (new FieldKeys())->derive($content, $contributions);
        try {
            $form = new Form($formId, 0, $placement, null, false, 0);
            (new DefinitionParser())->parse($form, 0, $content, $contributions);
        } catch (SystemFailure $e) {
            return self::split($e);
        }

        return null;
    }

    /** The refusal's text for a problem. */
    public static function message(string $code, string $subject): string
    {
        return sprintf(self::MESSAGE, $code, $subject);
    }

    /**
     * `rest_pre_insert_kalaka_form`: refuses a REST save that would publish a broken definition.
     *
     * @param \stdClass $prepared the post as the REST controller prepared it
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public static function onRestInsert(\stdClass $prepared, \WP_REST_Request $request): \stdClass|\WP_Error
    {
        $id = isset($prepared->ID) && is_int($prepared->ID) ? $prepared->ID : 0;
        $stored = $id > 0 ? get_post($id) : null;
        $status = is_string($prepared->post_status ?? null)
            ? $prepared->post_status
            : ($stored instanceof \WP_Post ? $stored->post_status : 'draft');
        $content = is_string($prepared->post_content ?? null)
            ? $prepared->post_content
            : ($stored instanceof \WP_Post ? $stored->post_content : '');
        $meta = $request->get_param('meta');
        $placement = self::placement(is_array($meta) ? ($meta[FormPosts::META_PLACEMENT] ?? null) : null, $id);

        $problem = $status === 'publish' && $placement !== null ? self::problem($content, $id, $placement) : null;
        if ($problem === null) {
            return $prepared;
        }

        return new \WP_Error('kalaka_form_definition', self::message(...$problem), ['status' => 400]);
    }

    /**
     * `wp_insert_post_data`, after the keys are derived: the backstop for every other save path.
     *
     * @param array<string, mixed> $data the post's fields, slashed
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     * @throws InvalidInput when a published form would be broken
     */
    public static function onInsert(array $data, array $postarr): array
    {
        if (
            ($data['post_type'] ?? null) !== FormPosts::POST_TYPE
            || ($data['post_status'] ?? null) !== 'publish'
            || !is_string($data['post_content'] ?? null)
        ) {
            return $data;
        }
        $id = is_int($postarr['ID'] ?? null) ? $postarr['ID'] : 0;
        $meta = is_array($postarr['meta_input'] ?? null) ? $postarr['meta_input'] : [];
        $placement = self::placement($meta[FormPosts::META_PLACEMENT] ?? null, $id);
        $problem = $placement !== null ? self::problem(wp_unslash($data['post_content']), $id, $placement) : null;
        if ($problem !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped by whoever shows it
            throw new InvalidInput(self::message(...$problem));
        }

        return $data;
    }

    /** The placement being saved, else the stored one, else null. */
    private static function placement(mixed $given, int $id): ?FormPlacement
    {
        if (is_string($given) && $given !== '') {
            return FormPlacement::tryFrom($given);
        }
        $stored = $id > 0 ? get_post_meta($id, FormPosts::META_PLACEMENT, true) : '';

        return is_string($stored) ? FormPlacement::tryFrom($stored) : null;
    }

    /**
     * The code and the subject of a parser's `SystemFailure`, whose context is `<code> <subject>`.
     *
     * @return array{string, string}
     */
    public static function split(SystemFailure $e): array
    {
        $default = (new SystemFailure())->getMessage();
        $context = trim(str_starts_with($e->getMessage(), $default)
            ? substr($e->getMessage(), strlen($default))
            : $e->getMessage());
        $parts = explode(' ', $context, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
