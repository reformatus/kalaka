<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory\Cli;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Support\Directory\NodeLookup;
use Kalaka\Support\Directory\SaveDirectoryNode;

/**
 * Reads an import file with one column per level and saves its nodes through
 * `kalaka/save-directory-node`, one use case and one transaction per node, so one bad row never
 * blocks the others. Free of WP-CLI so tests can run it. foundation.md, "Bulk changes".
 *
 * Pass one reads every row and builds the nodes by label path (the filled level cells up to the
 * node's own, each with its level): a row's node is in its last filled level cell, the cells
 * before it name its ancestors, and an ancestor without a row of its own is implied. It resolves
 * the external IDs (the row's `id`, or else the parent's external ID, `/` and the slug of the name)
 * and refuses duplicates and clashes. Pass two saves parents before children, by depth and then
 * file order; a node already stored as the file has it does not run the use case.
 *
 * @phpstan-type ImportedRow array{
 *     line: int,
 *     external_id: string,
 *     implied: bool,
 *     outcome: 'created'|'updated'|'unchanged'|'failed',
 *     message: ?string
 * }
 */
final class DirectoryImport
{
    private const RESERVED = ['id', 'aliases', 'active'];

    public function __construct(
        private readonly Runner $runner,
        private readonly SaveDirectoryNode $useCase = new SaveDirectoryNode(),
        private readonly NodeLookup $lookup = new NodeLookup(),
    ) {
    }

    /**
     * The entries come in file order, one per data row and one per implied ancestor; `line` is the
     * file line where the row starts (the header being line 1), for an implied ancestor the line of
     * the first row that implies it. `unmentioned` lists the active stored nodes below the file's
     * roots that the file does not name.
     *
     * @return array{entries: list<ImportedRow>, unmentioned: list<array{external_id: string, name: string}>}
     * @throws \InvalidArgumentException with the reason when the file is refused, before any node is saved
     */
    public function run(string $path, string $delimiter, Actor $actor): array
    {
        if (strlen($delimiter) !== 1) {
            $error = self::refused('The delimiter must be a single character.');
            throw $error;
        }

        [$header, $records] = self::read(self::contents($path), $delimiter);
        [$levels, $reserved] = self::header($header);

        /** @var array<string, ImportNode> $nodes */
        $nodes = [];
        $entries = [];
        foreach ($records as [$line, $cells]) {
            $failure = self::addRow($nodes, $levels, $reserved, $line, $cells);
            if ($failure !== null) {
                $entries[] = $failure;
            }
        }

        uasort(
            $nodes,
            static fn (ImportNode $a, ImportNode $b): int => [$a->depth, $a->line] <=> [$b->depth, $b->line]
        );
        self::resolveIds($nodes);
        $this->save($nodes, $actor);

        // on a line, the ancestors it implies come first, shallowest first
        $sorted = [];
        foreach ($entries as $entry) {
            $sorted[] = [[$entry['line'], 1, 0], $entry];
        }
        foreach ($nodes as $node) {
            $implied = $node->implied();
            $outcome = $node->error === null ? $node->outcome : 'failed';
            $error = $node->error ?? 'Not saved.';
            foreach ($implied ? [$node->line] : $node->lines as $line) {
                $sorted[] = [[$line, $implied ? 0 : 1, $node->depth], [
                    'line' => $line,
                    'external_id' => $node->externalId ?? '',
                    'implied' => $implied,
                    'outcome' => $outcome ?? 'failed',
                    'message' => $outcome === null || $outcome === 'failed' ? $error : null,
                ]];
            }
        }
        usort($sorted, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $entries = array_column($sorted, 1);

        return ['entries' => $entries, 'unmentioned' => $this->unmentioned($nodes)];
    }

    /**
     * The slug of a name, the last part of a derived external ID: accents removed, lowercase, and
     * every run of other characters than a–z and 0–9 replaced by `_`, trimmed of `_` at both ends.
     * A fixed locale keeps the accent map, and so the IDs, the same on every site.
     */
    public static function slug(string $name): string
    {
        $plain = strtolower(remove_accents($name, 'hu_HU'));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $plain), '_');
    }

    private static function refused(string $reason): \InvalidArgumentException
    {
        return new \InvalidArgumentException($reason);
    }

    /** The file's text without a leading byte order mark. */
    private static function contents(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            $error = self::refused('Cannot read the file: ' . $path);
            throw $error;
        }
        $file = new \SplFileObject($path, 'rb');
        $size = (int) $file->getSize();
        $text = $size === 0 ? '' : (string) $file->fread($size);

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $error = self::refused('The file is not valid UTF-8.');
            throw $error;
        }

        return $text;
    }

    /**
     * The header's cells and the data records with the file line each starts on. Blank lines, and
     * lines empty after trimming, are skipped.
     *
     * @return array{list<string>, list<array{int, list<string>}>}
     */
    private static function read(string $text, string $delimiter): array
    {
        $records = new \SplTempFileObject();
        $records->fwrite($text);
        $records->rewind();

        $header = null;
        $rows = [];
        $line = 1;
        $offset = 0;
        while (!$records->eof()) {
            $cells = $records->fgetcsv($delimiter, '"', '');
            // The record's place in the file: a quoted cell may span lines, so count the line breaks read.
            $start = $line;
            $end = (int) $records->ftell();
            $line += substr_count($text, "\n", $offset, $end - $offset);
            $offset = $end;
            if (!is_array($cells) || $cells === [null]) {
                continue;
            }
            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), $cells);
            if ($cells === ['']) {
                continue;
            }

            if ($header === null) {
                $header = $cells;
                continue;
            }
            $rows[] = [$start, $cells];
        }
        if ($header === null) {
            $error = self::refused('The file has no header row.');
            throw $error;
        }

        return [$header, $rows];
    }

    /**
     * @param list<string> $cells
     * @return array{list<string>, array<string, int>} the level types, top level first, and the
     *     reserved columns with their places
     */
    private static function header(array $cells): array
    {
        if (count($cells) === 1 && preg_match('/[,;\t|]/', $cells[0]) === 1) {
            $error = self::refused(
                'The header has one column: ' . $cells[0] . ' (check the delimiter, --delimiter=<char>)'
            );
            throw $error;
        }

        $levels = [];
        $reserved = [];
        foreach ($cells as $place => $name) {
            if ($name === '') {
                $error = self::refused('The header has an empty column name in column ' . ($place + 1) . '.');
                throw $error;
            }
            if (in_array($name, $levels, true) || isset($reserved[$name])) {
                $error = self::refused('Duplicate column: ' . $name);
                throw $error;
            }
            if (in_array($name, self::RESERVED, true)) {
                $reserved[$name] = $place;
            } elseif ($reserved !== []) {
                $error = self::refused('Unknown column: ' . $name);
                throw $error;
            } else {
                $levels[] = $name;
            }
        }
        if ($levels === []) {
            $error = self::refused('The header has no level column before id, aliases and active.');
            throw $error;
        }

        return [$levels, $reserved];
    }

    /**
     * Adds a row's node and the ancestors it names. Returns the failed entry of a row that has no
     * node (the wrong number of cells, an empty first level), null otherwise.
     *
     * @param array<string, ImportNode> $nodes
     * @param list<string> $levels
     * @param array<string, int> $reserved
     * @param list<string> $cells
     * @return ?ImportedRow
     */
    private static function addRow(array &$nodes, array $levels, array $reserved, int $line, array $cells): ?array
    {
        $width = count($levels) + count($reserved);
        if (count($cells) !== $width) {
            $id = isset($reserved['id']) ? ($cells[$reserved['id']] ?? '') : '';

            return self::failedRow($line, $id, 'Found ' . count($cells) . ' cells, expected ' . $width . '.');
        }
        if ($cells[0] === '') {
            $id = isset($reserved['id']) ? $cells[$reserved['id']] : '';

            return self::failedRow($line, $id, 'The first level cell is empty.');
        }

        $path = [];
        $parent = null;
        $filled = array_keys(array_filter(
            array_slice($cells, 0, count($levels)),
            static fn (string $cell): bool => $cell !== ''
        ));
        foreach ($filled as $depth => $level) {
            $path[] = [$level, $cells[$level]];
            $key = (string) wp_json_encode($path, JSON_UNESCAPED_UNICODE);
            $node = $nodes[$key] ??= new ImportNode($depth, $levels[$level], $cells[$level], $parent, $line);
            $parent = $key;
            if ($depth < count($filled) - 1) {
                continue;
            }

            if (!$node->implied()) {
                $node->lines[] = $line;
                $node->error = 'Duplicate row: the same levels are on lines ' . implode(', ', $node->lines) . '.';

                return null;
            }
            // the row wins over the ancestor it may have been implied as
            $aliases = isset($reserved['aliases']) ? $cells[$reserved['aliases']] : '';
            $active = isset($reserved['active']) ? $cells[$reserved['active']] : '';
            $node->lines = [$line];
            $node->line = $line;
            $node->id = isset($reserved['id']) ? $cells[$reserved['id']] : '';
            $node->aliases = $aliases === '' ? [] : array_map('trim', explode('|', $aliases));
            // Anything but 1, 0 or empty goes on as it is, for the use case's schema to refuse.
            $node->active = match ($active) {
                '', '1' => true,
                '0' => false,
                default => $active,
            };
        }

        return null;
    }

    /**
     * Gives every node its external ID, parents first, and fails clashing nodes and the nodes below
     * a failed one.
     *
     * @param array<string, ImportNode> $nodes in depth order
     */
    private static function resolveIds(array $nodes): void
    {
        foreach ($nodes as $node) {
            $parent = $node->parent === null ? null : $nodes[$node->parent];
            if ($node->id !== '') {
                $node->externalId = $node->id;
            } elseif ($parent !== null && $parent->externalId === null) {
                continue;
            } else {
                $slug = self::slug($node->name);
                if ($slug === '') {
                    $node->error ??= 'The name has no letter or digit to make an external ID of; give an id.';
                    continue;
                }
                $node->externalId = $parent === null ? $slug : $parent->externalId . '/' . $slug;
            }
        }

        $byId = [];
        foreach ($nodes as $node) {
            if ($node->externalId !== null) {
                $byId[$node->externalId][] = $node;
            }
        }
        foreach ($byId as $externalId => $same) {
            if (count($same) < 2) {
                continue;
            }
            $lines = array_map(static fn (ImportNode $node): int => $node->line, $same);
            sort($lines);
            foreach ($same as $node) {
                $node->error ??= 'The external ID ' . $externalId . ' belongs to more than one node, on lines '
                    . implode(', ', $lines) . '.';
            }
        }

        foreach ($nodes as $node) {
            self::failBelowFailed($nodes, $node);
        }
    }

    /**
     * Fails the node when its parent failed.
     *
     * @param array<string, ImportNode> $nodes
     */
    private static function failBelowFailed(array $nodes, ImportNode $node): void
    {
        $parent = $node->parent === null ? null : $nodes[$node->parent];
        if ($parent === null || $node->error !== null || $parent->error === null) {
            return;
        }
        $node->error = 'The parent on line ' . $parent->line . ' failed.';
    }

    /**
     * Pass two: saves the nodes parents first. A stored node the file has as it is, and a stored
     * implied ancestor, run no use case. A parent that fails here fails its children only when it
     * was not stored before.
     *
     * @param array<string, ImportNode> $nodes in depth order
     */
    private function save(array $nodes, Actor $actor): void
    {
        $ids = [];
        foreach ($nodes as $node) {
            if ($node->externalId !== null) {
                $ids[] = $node->externalId;
            }
        }
        $stored = $this->lookup->byExternalIds($ids);

        foreach ($nodes as $node) {
            $parent = $node->parent === null ? null : $nodes[$node->parent];
            if ($parent !== null && !isset($stored[(string) $parent->externalId])) {
                self::failBelowFailed($nodes, $node);
            }
            if ($node->error !== null || $node->externalId === null) {
                continue;
            }

            $input = [
                'external_id' => $node->externalId,
                'parent_external_id' => $parent?->externalId,
                'type' => $node->type,
                'name' => $node->name,
                'aliases' => $node->aliases,
                'active' => $node->active,
            ];
            $current = $stored[$node->externalId] ?? null;
            $asInFile = $input;
            unset($asInFile['external_id']);
            if ($current !== null && ($node->implied() || $current === $asInFile)) {
                $node->outcome = 'unchanged';
                continue;
            }

            try {
                $result = $this->runner->run($this->useCase, $input, $actor);
            } catch (KalakaException $e) {
                $node->error = $e->getMessage();
                continue;
            }

            $outcome = is_array($result) ? ($result['outcome'] ?? null) : null;
            if (!in_array($outcome, ['created', 'updated', 'unchanged'], true)) {
                $node->error = 'The use case returned no outcome.';
                continue;
            }
            $node->outcome = $outcome;
        }
    }

    /**
     * The active stored nodes below the file's roots that the file names neither as a row nor as
     * an implied ancestor.
     *
     * @param array<string, ImportNode> $nodes
     * @return list<array{external_id: string, name: string}>
     */
    private function unmentioned(array $nodes): array
    {
        $roots = [];
        $named = [];
        foreach ($nodes as $node) {
            if ($node->externalId === null) {
                continue;
            }
            $named[$node->externalId] = true;
            if ($node->parent === null) {
                $roots[] = $node->externalId;
            }
        }

        return array_values(array_filter(
            $this->lookup->activeBelow($roots),
            static fn (array $node): bool => !isset($named[$node['external_id']])
        ));
    }

    /** @return ImportedRow */
    private static function failedRow(int $line, string $externalId, string $message): array
    {
        return [
            'line' => $line,
            'external_id' => $externalId,
            'implied' => false,
            'outcome' => 'failed',
            'message' => $message,
        ];
    }
}
