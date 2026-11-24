<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * A validated filter and column list: foundation.md, "Views and grants". The filter is a list of terms
 * that must all hold; a term is a column with the strings it may hold, or the one parameter term
 * (`in` null) that is bound when access is checked. The stored JSON is always the canonical form.
 *
 * `counts` are the members the grid's sum row counts per column (S37): a listed `status`, `field:` or `decision:`
 * column with the values to count, each once. A column without an entry counts its default. Whether a
 * value is a member of the column is the saver's check (`SaveView`), which knows the field's options.
 *
 * The master row's shape (`forMaster()`) has no filter and no columns, only counts, whose columns are
 * `status` or any `field:` column: the master view lists every column of the event.
 */
final readonly class ViewShape
{
    private const FIELD_KEY = '/^[a-z][a-z0-9_]{0,63}$/D';

    private const DECISION_KIND = '/^[a-z][a-z0-9_]*$/D';

    private const KIND_LENGTH = 50;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param list<array{column: string, in: ?list<string>}> $filter
     * @param list<array{column: string, write: bool}> $columns
     * @param array<string, list<string>> $counts by column, in column order
     */
    private function __construct(public array $filter, public array $columns, public array $counts = [])
    {
    }

    /**
     * @param mixed $counts null or an object of column → list of member values
     * @throws InvalidInput
     */
    public static function of(mixed $filter, mixed $columns, mixed $counts = null): self
    {
        $list = self::columnList($columns);

        return new self(self::terms($filter), $list, self::countMap($counts, $list));
    }

    /**
     * The shape of a stored view.
     *
     * @param ?string $counts null or empty: nothing stored
     * @throws SystemFailure when the stored JSON is not a valid shape
     */
    public static function fromStored(string $filter, string $columns, ?string $counts = null): self
    {
        try {
            return self::of(
                json_decode($filter, true),
                json_decode($columns, true),
                $counts === null || $counts === '' ? null : json_decode($counts, true)
            );
        } catch (InvalidInput $e) {
            $error = new SystemFailure(null, new \UnexpectedValueException('A stored view is malformed.', 0, $e));
            throw $error;
        }
    }

    /**
     * The shape of the event's master row (S37): only the counts.
     *
     * @param mixed $counts null or an object of column → list of member values
     * @throws InvalidInput
     */
    public static function forMaster(mixed $counts): self
    {
        return new self([], [], self::countMap($counts, null));
    }

    /**
     * The shape of a stored master row.
     *
     * @throws SystemFailure when the stored counts are malformed
     */
    public static function fromStoredMaster(?string $counts): self
    {
        try {
            return self::forMaster($counts === null || $counts === '' ? null : json_decode($counts, true));
        } catch (InvalidInput $e) {
            $cause = new \UnexpectedValueException('A stored master view is malformed.', 0, $e);
            $error = new SystemFailure(null, $cause);
            throw $error;
        }
    }

    /**
     * The master view: no filter, `code`, `status` and `serial`, every given field key as a read-write
     * column, then every given decision kind.
     *
     * @param list<string> $fieldKeys
     * @param list<string> $decisionKinds
     * @throws InvalidInput
     */
    public static function master(array $fieldKeys, array $decisionKinds): self
    {
        $columns = [
            ['column' => 'code', 'write' => false],
            ['column' => 'status', 'write' => false],
            ['column' => 'serial', 'write' => false],
        ];
        foreach ($fieldKeys as $key) {
            $columns[] = ['column' => 'field:' . $key, 'write' => true];
        }
        foreach ($decisionKinds as $kind) {
            $columns[] = ['column' => 'decision:' . $kind, 'write' => false];
        }

        return self::of([], $columns);
    }

    public function filterJson(): string
    {
        $terms = [];
        foreach ($this->filter as $term) {
            $terms[] = $term['in'] === null
                ? ['column' => $term['column'], 'parameter' => true]
                : ['column' => $term['column'], 'in' => $term['in']];
        }

        return self::encode($terms);
    }

    public function columnsJson(): string
    {
        return self::encode($this->columns);
    }

    /** The counts as stored: a JSON object, `{}` when there are none. */
    public function countsJson(): string
    {
        if ($this->counts === []) {
            return '{}';
        }

        return self::encode($this->counts);
    }

    /**
     * The same shape with the counted members of one listed column replaced; an empty list drops the entry,
     * so the column counts its default again.
     *
     * @param list<string> $members
     * @throws InvalidInput when the column cannot carry counts
     */
    public function withCounts(string $column, array $members): self
    {
        $counts = $this->counts;
        $counts[$column] = $members;
        if ($members === []) {
            unset($counts[$column]);
        }

        // Only a master row's shape has no columns: every view lists at least one.
        return new self(
            $this->filter,
            $this->columns,
            self::countMap($counts, $this->columns === [] ? null : $this->columns)
        );
    }

    public function hasParameter(): bool
    {
        return $this->parameterColumn() !== null;
    }

    /** The column of the parameter term, bound per grant; null when the filter has none. */
    public function parameterColumn(): ?string
    {
        foreach ($this->filter as $term) {
            if ($term['in'] === null) {
                return $term['column'];
            }
        }

        return null;
    }

    /** Whether the column is listed and read-write. */
    public function writes(string $column): bool
    {
        foreach ($this->columns as $listed) {
            if ($listed['column'] === $column) {
                return $listed['write'];
            }
        }

        return false;
    }

    public function lists(string $column): bool
    {
        foreach ($this->columns as $listed) {
            if ($listed['column'] === $column) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the field keys of the field columns, in column order */
    public function fieldKeys(): array
    {
        return $this->suffixes('field:');
    }

    /** @return list<string> the kinds of the decision columns, in column order */
    public function decisionKinds(): array
    {
        return $this->suffixes('decision:');
    }

    /** @return list<string> */
    private function suffixes(string $prefix): array
    {
        $suffixes = [];
        foreach ($this->columns as $listed) {
            if (str_starts_with($listed['column'], $prefix)) {
                $suffixes[] = substr($listed['column'], strlen($prefix));
            }
        }

        return $suffixes;
    }

    /**
     * @return list<array{column: string, in: ?list<string>}>
     * @throws InvalidInput
     */
    private static function terms(mixed $filter): array
    {
        if (!is_array($filter) || !array_is_list($filter)) {
            throw new InvalidInput();
        }

        $terms = [];
        $parameters = 0;
        foreach ($filter as $term) {
            if (!is_array($term) || !is_string($term['column'] ?? null) || !self::validColumn($term['column'])) {
                throw new InvalidInput();
            }
            $column = $term['column'];
            if (in_array($column, ['code', 'serial', 'registered_at', 'submitted_at'], true)) {
                throw new InvalidInput();
            }

            if (self::hasExactly($term, 'column', 'parameter') && $term['parameter'] === true) {
                $parameters++;
                $terms[] = ['column' => $column, 'in' => null];
                continue;
            }
            if (!self::hasExactly($term, 'column', 'in')) {
                throw new InvalidInput();
            }
            $terms[] = ['column' => $column, 'in' => self::values($column, $term['in'])];
        }
        if ($parameters > 1) {
            throw new InvalidInput();
        }

        return $terms;
    }

    /**
     * @return list<string>
     * @throws InvalidInput
     */
    private static function values(string $column, mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || $values === []) {
            throw new InvalidInput();
        }
        foreach ($values as $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidInput();
            }
            if ($column === 'status' && RegistrationStatus::tryFrom($value) === null) {
                throw new InvalidInput();
            }
        }

        return $values;
    }

    /**
     * @return list<array{column: string, write: bool}>
     * @throws InvalidInput
     */
    private static function columnList(mixed $columns): array
    {
        if (!is_array($columns) || !array_is_list($columns) || $columns === []) {
            throw new InvalidInput();
        }

        $list = [];
        $seen = [];
        foreach ($columns as $item) {
            if (
                !is_array($item)
                || !self::hasExactly($item, 'column', 'write')
                || !is_string($item['column'])
                || !is_bool($item['write'])
                || !self::validColumn($item['column'])
                || isset($seen[$item['column']])
                || ($item['write'] && !str_starts_with($item['column'], 'field:'))
            ) {
                throw new InvalidInput();
            }
            $seen[$item['column']] = true;
            $list[] = ['column' => $item['column'], 'write' => $item['write']];
        }

        return $list;
    }

    /**
     * The counts in column order (for the master row, whose columns are not listed: `status` first, then the
     * field columns by key); an empty member list is dropped.
     *
     * @param ?list<array{column: string, write: bool}> $columns null: the master row's, every column
     * @return array<string, list<string>>
     * @throws InvalidInput
     */
    private static function countMap(mixed $counts, ?array $columns): array
    {
        if ($counts === null || $counts === []) {
            return [];
        }
        if (!is_array($counts) || array_is_list($counts)) {
            throw new InvalidInput();
        }

        if ($columns === null) {
            $listed = array_map('strval', array_keys($counts));
            usort($listed, static fn (string $a, string $b): int => [$a !== 'status', $a] <=> [$b !== 'status', $b]);
        } else {
            $listed = array_column($columns, 'column');
        }
        foreach (array_keys($counts) as $column) {
            $column = (string) $column;
            if (
                !in_array($column, $listed, true)
                || !self::validColumn($column)
                || (!in_array($column, ['status'], true) && !str_starts_with($column, 'field:')
                    && !str_starts_with($column, 'decision:'))
            ) {
                throw new InvalidInput();
            }
        }

        $map = [];
        foreach ($listed as $column) {
            if (!array_key_exists($column, $counts)) {
                continue;
            }
            $members = $counts[$column];
            if (!is_array($members) || !array_is_list($members)) {
                throw new InvalidInput();
            }
            $seen = [];
            foreach ($members as $member) {
                if (
                    !is_string($member)
                    || $member === ''
                    || !mb_check_encoding($member, 'UTF-8')
                    || isset($seen[$member])
                ) {
                    throw new InvalidInput();
                }
                if ($column === 'status' && RegistrationStatus::tryFrom($member) === null) {
                    throw new InvalidInput();
                }
                $seen[$member] = true;
            }
            if ($members !== []) {
                $map[$column] = $members;
            }
        }

        return $map;
    }

    /** @param array<mixed> $item */
    private static function hasExactly(array $item, string ...$keys): bool
    {
        return count($item) === count($keys) && array_diff_key(array_flip($keys), $item) === [];
    }

    private static function validColumn(string $column): bool
    {
        if (in_array($column, ['code', 'status', 'serial', 'registered_at', 'submitted_at'], true)) {
            return true;
        }
        if (str_starts_with($column, 'field:')) {
            return preg_match(self::FIELD_KEY, substr($column, 6)) === 1;
        }
        if (str_starts_with($column, 'decision:')) {
            $kind = substr($column, 9);

            return strlen($kind) <= self::KIND_LENGTH && preg_match(self::DECISION_KIND, $kind) === 1;
        }

        return false;
    }

    /** @param array<array-key, mixed> $list */
    private static function encode(array $list): string
    {
        $json = wp_json_encode($list, self::JSON_FLAGS);
        if ($json === false) {
            $error = new SystemFailure(null, new \LogicException('A view shape is not JSON-encodable.'));
            throw $error;
        }

        return $json;
    }
}
