<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Directory;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\UseCase\UseCase;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Support\Directory\SaveDirectoryNode;
use Kalaka\Tests\Integration\Foundation\AuditTestCase;

// Core's test helpers sit next to this plugin under wp-content/plugins/.
require_once __DIR__ . '/../../../../kalaka/tests/integration/Foundation/AuditTestCase.php';

/**
 * The base of the directory tests: a runner in savepoint mode with a fixed clock, a full-access
 * admin, and the synthetic tree. Root "Teszt Egyház" (Felekezet) has two districts, each with two
 * deaneries of three congregations; a second root "Másik Közösség" has no levels.
 */
abstract class SupportTestCase extends AuditTestCase
{
    protected UserActor $admin;

    public function set_up(): void
    {
        parent::set_up();
        ObjectCache::clear();
        $this->admin = new UserActor($this->fullAccessUser());
    }

    public function tear_down(): void
    {
        ObjectCache::clear();
        parent::tear_down();
    }

    /** @param array<string, mixed> $input */
    protected function exec(UseCase $useCase, array $input, ?Actor $actor = null): mixed
    {
        return $this->runner->run($useCase, $input, $actor ?? $this->admin);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> a complete input for the use case
     */
    protected function input(array $overrides = []): array
    {
        return $overrides + [
            'external_id' => 'TEST-1',
            'parent_external_id' => null,
            'type' => 'Felekezet',
            'name' => 'Teszt Egyház',
            'aliases' => [],
            'active' => true,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{id: int, outcome: string}
     */
    protected function save(array $overrides = [], ?Actor $actor = null): array
    {
        $result = $this->exec(new SaveDirectoryNode(), $this->input($overrides), $actor);
        self::assertIsArray($result);
        self::assertIsInt($result['id']);
        self::assertIsString($result['outcome']);

        return ['id' => $result['id'], 'outcome' => $result['outcome']];
    }

    /**
     * Saves the synthetic tree.
     *
     * @return array<string, int> the node ids by external ID
     */
    protected function buildTree(): array
    {
        $ids = [];
        $add = function (string $externalId, ?string $parent, string $type, string $name, array $aliases = []) use (&$ids): void {
            $ids[$externalId] = $this->save([
                'external_id' => $externalId,
                'parent_external_id' => $parent,
                'type' => $type,
                'name' => $name,
                'aliases' => $aliases,
            ])['id'];
        };

        $add('ROOT', null, 'Felekezet', 'Teszt Egyház');
        $add('OTHER', null, 'Közösség', 'Másik Közösség');
        $add('D1', 'ROOT', 'Egyházkerület', 'Északi Teszt Egyházkerület');
        $add('D2', 'ROOT', 'Egyházkerület', 'Déli Teszt Egyházkerület');
        $add('D1-A', 'D1', 'Egyházmegye', 'Budai Teszt Egyházmegye');
        $add('D1-B', 'D1', 'Egyházmegye', 'Pesti Teszt Egyházmegye');
        $add('D2-A', 'D2', 'Egyházmegye', 'Szegedi Teszt Egyházmegye');
        $add('D2-B', 'D2', 'Egyházmegye', 'Pécsi Teszt Egyházmegye');

        $add('D1-A-1', 'D1-A', 'Gyülekezet', 'Óbudai Teszt Gyülekezet');
        $add('D1-A-2', 'D1-A', 'Gyülekezet', 'Ottó Teszt');
        $add('D1-A-3', 'D1-A', 'Gyülekezet', 'Öröm Teszt');
        $add('D1-B-1', 'D1-B', 'Gyülekezet', 'Teszt Fasori Gyülekezet', ['Teszt Fasor Kápolna']);
        $add('D1-B-2', 'D1-B', 'Gyülekezet', 'Teszt Fasor Gyülekezet');
        $add('D1-B-3', 'D1-B', 'Gyülekezet', 'Keleti Teszt Gyülekezet');
        $add('D2-A-1', 'D2-A', 'Gyülekezet', 'Teszt Alsóvárosi Gyülekezet', ['Rókusi Teszt']);
        $add('D2-A-2', 'D2-A', 'Gyülekezet', 'Újszegedi Teszt Gyülekezet');
        $add('D2-A-3', 'D2-A', 'Gyülekezet', 'Tápéi Teszt Gyülekezet');
        $add('D2-B-1', 'D2-B', 'Gyülekezet', 'Teszt Belvárosi Gyülekezet');
        $add('D2-B-2', 'D2-B', 'Gyülekezet', 'Mecsekaljai Teszt Gyülekezet');
        $add('D2-B-3', 'D2-B', 'Gyülekezet', 'Siklósi Teszt Gyülekezet');

        return $ids;
    }

    /** @return array<string, ?string> */
    protected function row(int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}kalaka_support_nodes WHERE id = %d", $id),
            ARRAY_A
        );
        self::assertIsArray($row);

        return $row;
    }

    protected function nodeCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_support_nodes");
    }

    /** @return list<string> the statements the database receives while the work runs */
    protected function statementsDuring(\Closure $work): array
    {
        $seen = [];
        $capture = static function (string $query) use (&$seen): string {
            $seen[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        try {
            $work();
        } finally {
            remove_filter('query', $capture);
        }

        return $seen;
    }

    /**
     * @param array<string, ?string> $entry
     * @return list<string> the changed fields of the entry, in the order recorded
     */
    protected function changedFields(array $entry): array
    {
        /** @var list<array<string, mixed>> $changes */
        $changes = $entry['changes'] === null ? [] : $this->decode($entry['changes']);

        return array_map(static fn (array $change): string => (string) $change['field'], $changes);
    }
}
