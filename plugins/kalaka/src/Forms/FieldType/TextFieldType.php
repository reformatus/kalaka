<?php

declare(strict_types=1);

namespace Kalaka\Forms\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** Free text: trimmed, at most 500 characters on one line, or 5,000 when `multiline`. */
final class TextFieldType extends AbstractFieldType
{
    public const MAX_LENGTH = 500;
    public const MAX_LENGTH_MULTILINE = 5000;

    public function blockName(): string
    {
        return 'kalaka/text-field';
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $multiline = ($input->attributes['multiline'] ?? false) === true;
        $text = self::trimmed($input->value);
        $text = $multiline
            ? preg_replace('/\r\n?/', "\n", $text)
            : preg_replace('/\s*\R\s*/u', ' ', $text);
        $text ??= $input->value;

        $limit = $multiline ? self::MAX_LENGTH_MULTILINE : self::MAX_LENGTH;
        if (mb_strlen($text) > $limit) {
            return FieldAnswer::error(self::tooLong($limit));
        }

        return FieldAnswer::stored($text);
    }

    /** The message of a text over its limit, also used by the name. */
    public static function tooLong(int $limit): string
    {
        return sprintf('Legfeljebb %d karaktert írhatsz ide.', $limit);
    }
}
