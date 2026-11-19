<?php

declare(strict_types=1);

namespace Kalaka\Support\Picker;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\MoneyRelevantFieldType;
use Kalaka\Forms\Api\PathFieldType;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * The congregation field (money.md, "Sponsor and church support"): its answer is one terminal leaf of the
 * organization directory (owner ruling S28-1: no free text and no "not in the list"; admins add an "egyéb"
 * leaf by hand where one is needed). A new answer must be live (active, under active ancestors) and have no
 * children; the node is read when the answer is checked, so a deactivated node or a level is refused.
 *
 * The stored answer is JSON, `{"node":<id>,"path":[{"ext":"…","type":"…"},…]}`: the
 * leaf's id and a snapshot of its path, each node's external ID and type, root first, ending with the leaf,
 * taken when the answer is checked. A new answer takes a new snapshot; a re-import of the directory never
 * changes a stored one.
 *
 * For a condition the value is a choice whose data is the path's external IDs, root
 * first (`PathFieldType`): "<level> is <one node>" is `contains <its external ID>`, and `equals` is refused.
 * External IDs stay the same between the imports of two sites; database ids never show in a value. Its text
 * is the leaf's live name from the directory (owner ruling, 2026-11-18): a node is never deleted, so a stored
 * id always resolves. An answer stored as a bare id (before the snapshot) reads its path from the directory
 * when it is read.
 *
 * Money-relevant: the congregation share and the support offers read it (S29, S30).
 *
 * TODO(S60/S73) a stored node that is no longer live or no longer a leaf stays valid when an additional form
 * sends it back unchanged; the check does not know the current value yet, so such a resend is refused for now.
 */
final class CongregationFieldType extends AbstractFieldType implements MoneyRelevantFieldType, PathFieldType
{
    public const BLOCK_NAME = 'kalaka/congregation-field';

    /** The error of an answer that is not a live terminal leaf. */
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
        $id = (int) $sent;
        if (!$this->nodes->isLiveLeaf($id)) {
            return FieldAnswer::error(self::NOT_LISTED);
        }

        return FieldAnswer::stored(self::encode($id, $this->nodes->path($id)));
    }

    public function valueType(): ValueType
    {
        return ValueType::Choice;
    }

    /** The path's external IDs as the data, the leaf's live name as the text; nothing chosen for no node. */
    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? $input->value : '';
        $snapshot = self::decode($stored);
        $node = $snapshot === null ? null : $this->nodes->node($snapshot['node']);
        if ($snapshot === null || $node === null) {
            return Value::choice([], $snapshot === null ? $stored : '');
        }
        $path = $snapshot['path'] ?? $this->nodes->path($snapshot['node']);

        return Value::choice(array_column($path, 'ext'), $node['name']);
    }

    /** The picked leaf's id, as the field's hidden input holds it; null for none. */
    public function prefill(FieldTypeInput $input): mixed
    {
        $snapshot = is_string($input->value) ? self::decode($input->value) : null;

        return $snapshot === null ? null : (string) $snapshot['node'];
    }

    /**
     * The stored answer: the same text for the same pick and path, whatever the names hold.
     *
     * @param list<array{ext: string, type: string}> $path
     */
    public static function encode(int $id, array $path): string
    {
        $path = array_map(static fn (array $node): array => ['ext' => $node['ext'], 'type' => $node['type']], $path);

        return (string) wp_json_encode(
            ['node' => $id, 'path' => $path],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * A stored answer read back: the snapshot, or a bare id (stored before the snapshot) with no path, which
     * `value()` reads from the directory; null for anything else.
     *
     * @return ?array{node: int, path?: list<array{ext: string, type: string}>}
     */
    private static function decode(string $stored): ?array
    {
        if (preg_match(self::NODE_ID, $stored) === 1) {
            return (int) $stored === 0 ? null : ['node' => (int) $stored];
        }
        $data = json_decode($stored, true);
        if (
            !is_array($data) || !is_int($data['node'] ?? null) || $data['node'] <= 0
            || !is_array($data['path'] ?? null)
        ) {
            return null;
        }
        $path = [];
        foreach ($data['path'] as $node) {
            if (!is_array($node) || !is_string($node['ext'] ?? null) || !is_string($node['type'] ?? null)) {
                return null;
            }
            $path[] = ['ext' => $node['ext'], 'type' => $node['type']];
        }

        return ['node' => $data['node'], 'path' => $path];
    }
}
