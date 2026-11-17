<?php

declare(strict_types=1);

namespace Kalaka\Support\Picker;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\MoneyRelevantFieldType;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * The congregation field (money.md, "Sponsor and church support"): its answer is one terminal leaf of
 * the organization directory, and the stored value is that node's id alone (owner ruling S28-1: no
 * free text and no "not in the list"; admins add an "egyéb" leaf by hand where one is needed). A new
 * answer must be live (active, under active ancestors) and have no children; the node is read when
 * the answer is checked, so a deactivated node or a level is refused.
 *
 * For a condition the value is a choice of one: the node's external ID, which stays the same between
 * the imports of two sites, never its database id. Its text is the node's name. The value reads the
 * node whether it is live or not: a node is never deleted, so a stored id always resolves.
 *
 * Money-relevant: the congregation share and the support offers read it (S29, S30).
 *
 * TODO(S29/S30) the node's ancestors as computed facts (`<key>.ancestors`), put into the fact source by
 * the caller that needs them. TODO(S60/S73) a stored node that is no longer live or no longer a leaf
 * stays valid when an additional form sends it back unchanged; the check does not know the current
 * value yet, so such a resend is refused for now.
 */
final class CongregationFieldType extends AbstractFieldType implements MoneyRelevantFieldType
{
    public const BLOCK_NAME = 'kalaka/congregation-field';

    /** [UNAPPROVED N5] (Text) The error of an answer that is not a live terminal leaf. */
    public const NOT_LISTED = 'Érvénytelen választás. Válassz a listából.';

    /** A node id as the browser sends it: digits that fit in an integer. */
    private const NODE_ID = '/\A[0-9]{1,18}\z/';

    public function __construct(private readonly PickedNodes $nodes = new PickedNodes())
    {
    }

    public function blockName(): string
    {
        return self::BLOCK_NAME;
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $sent = self::trimmed($input->value);
        if (preg_match(self::NODE_ID, $sent) !== 1 || (int) $sent === 0) {
            return FieldAnswer::error(self::NOT_LISTED);
        }
        if (!$this->nodes->isLiveLeaf((int) $sent)) {
            return FieldAnswer::error(self::NOT_LISTED);
        }

        return FieldAnswer::stored((string) (int) $sent);
    }

    public function valueType(): ValueType
    {
        return ValueType::Choice;
    }

    /** The node's external ID as the data, its name as the text; nothing chosen for an id with no node. */
    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? $input->value : '';
        $node = preg_match(self::NODE_ID, $stored) === 1 ? $this->nodes->node((int) $stored) : null;

        return $node === null
            ? Value::choice([], $stored)
            : Value::choice([$node['external_id']], $node['name']);
    }
}
