<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Mail;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\PathFieldType;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;

/**
 * A path field type for the mail tests: its stored answer is the congregation answer's JSON, its value a
 * choice of the path's external IDs, root first. Core ships no such type; the Support extension's
 * congregation field is the real one.
 */
final class TestPathFieldType extends AbstractFieldType implements PathFieldType
{
    public const BLOCK = 'kalaka/test-path-field';

    public function blockName(): string
    {
        return self::BLOCK;
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        return is_string($input->value) && $input->value !== ''
            ? FieldAnswer::stored($input->value)
            : FieldAnswer::error('Válassz a listából.');
    }

    public function valueType(): ValueType
    {
        return ValueType::Choice;
    }

    public function value(FieldTypeInput $input): Value
    {
        $exts = [];
        $data = is_string($input->value) ? json_decode($input->value, true) : null;
        if (is_array($data) && is_array($data['path'] ?? null)) {
            foreach ($data['path'] as $node) {
                if (is_array($node) && is_string($node['ext'] ?? null)) {
                    $exts[] = $node['ext'];
                }
            }
        }

        return Value::choice($exts, '');
    }
}
