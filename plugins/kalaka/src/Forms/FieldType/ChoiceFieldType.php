<?php

declare(strict_types=1);

namespace Kalaka\Forms\FieldType;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** One option, or several when `multiple`; the options are a list of `{value, label}`. */
final class ChoiceFieldType extends AbstractFieldType
{
    public const BLOCK_NAME = 'kalaka/choice-field';

    public const OPTION_VALUE_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

    public function blockName(): string
    {
        return self::BLOCK_NAME;
    }

    /**
     * The option values of a choice field, or null when its options are not valid: a list of at
     * least one `{value, label}` with a valid value, none repeated.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>|null
     */
    public static function optionValues(array $attributes): ?array
    {
        $options = $attributes['options'] ?? null;
        if (!is_array($options) || $options === [] || !array_is_list($options)) {
            return null;
        }
        $values = [];
        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : null;
            if (
                !is_string($value)
                || preg_match(self::OPTION_VALUE_PATTERN, $value) !== 1
                || in_array($value, $values, true)
            ) {
                return null;
            }
            $values[] = $value;
        }

        return $values;
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        $allowed = self::optionValues($input->attributes) ?? [];
        $multiple = ($input->attributes['multiple'] ?? false) === true;
        $sent = $input->value;

        if (is_string($sent)) {
            $sent = [$sent];
            $wrongShape = $multiple;
        } elseif (is_array($sent) && array_is_list($sent)) {
            $wrongShape = !$multiple;
        } else {
            throw new InvalidInput();
        }
        foreach ($sent as $value) {
            if (!is_string($value)) {
                throw new InvalidInput();
            }
        }

        if ($wrongShape || array_diff($sent, $allowed) !== []) {
            return FieldAnswer::error('Válassz a megadott lehetőségek közül.');
        }
        $chosen = array_values(array_intersect($allowed, $sent));

        return FieldAnswer::stored($multiple ? (string) wp_json_encode($chosen) : $chosen[0]);
    }
}
