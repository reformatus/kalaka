<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory\Rest;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Support\Directory\NodeList;

/**
 * `GET /kalaka-support/v1/nodes`: the public search and child listing of the directory. Reads
 * only, so it runs no use case and writes no audit entry. Every parameter is checked in the
 * callback, so a bad one answers with InvalidInput's Hungarian text and not WordPress's English.
 */
final class NodesController
{
    private const NAMESPACE = 'kalaka-support/v1';

    public function __construct(private readonly NodeList $list)
    {
    }

    public function register(): void
    {
        Boundary::route(self::NAMESPACE, '/nodes', [
            [
                'methods' => 'GET',
                'callback' => $this->get(...),
                // public: anyone may read while the site is open;
                // a closed site answers with SiteClosed
                'permission_callback' => $this->permit(...),
                'args' => self::args(),
            ],
            'schema' => self::schema(...),
        ]);
    }

    /** @throws \Kalaka\Foundation\Api\Error\SiteClosed while the site is closed; the boundary answers with it */
    private function permit(): bool
    {
        LoadState::current()->requireOpen();

        return true;
    }

    private function get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $search = $request->get_param('search');
        $parent = $request->get_param('parent');
        $page = $request->get_param('page') ?? 1;
        $perPage = $request->get_param('per_page') ?? 10;

        $args = self::args();
        $search = is_string($search) ? trim($search) : $search;
        $checked = [];
        $given = ['search' => $search, 'parent' => $parent, 'page' => $page, 'per_page' => $perPage];
        foreach ($given as $name => $value) {
            if ($value === null) {
                continue;
            }
            $valid = rest_validate_value_from_schema($value, $args[$name], $name);
            if (is_wp_error($valid)) {
                $cause = new \InvalidArgumentException($valid->get_error_message());

                return (new InvalidInput(null, $cause))->toWpError();
            }
            $checked[$name] = rest_sanitize_value_from_schema($value, $args[$name], $name);
        }
        // a search of only Unicode whitespace (which trim() keeps) yields no words: no search
        if (isset($checked['search']) && NodeList::words((string) $checked['search']) === []) {
            unset($checked['search']);
        }
        if (!isset($checked['search']) && !isset($checked['parent'])) {
            return (new InvalidInput())->toWpError();
        }
        // the offset (page - 1) * per_page must fit in an integer
        if ((int) $checked['page'] - 1 > intdiv(PHP_INT_MAX, (int) $checked['per_page'])) {
            return (new InvalidInput())->toWpError();
        }

        // only live nodes are listed (NodeList)
        $result = $this->list->live(
            isset($checked['search']) ? (string) $checked['search'] : null,
            isset($checked['parent']) ? (int) $checked['parent'] : null,
            (int) $checked['page'],
            (int) $checked['per_page'],
        );

        $response = new \WP_REST_Response($result['items']);
        $response->header('X-WP-Total', (string) $result['total']);
        $pages = (int) ceil($result['total'] / (int) $checked['per_page']);
        $response->header('X-WP-TotalPages', (string) $pages);

        return $response;
    }

    /**
     * The route's arguments, which carry the schema for discovery. WordPress's type-based default
     * sanitizing (rest_parse_request_arg, which answers in English) must not run before the callback,
     * so each argument sets `sanitize_callback` to null explicitly; there is no validate callback.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function args(): array
    {
        $args = [
            'search' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100],
            'parent' => ['type' => 'integer', 'minimum' => 0],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
        ];

        return array_map(
            static fn (array $arg): array => $arg + ['sanitize_callback' => null],
            $args
        );
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $reference = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'type' => ['type' => 'string'],
            ],
        ];

        return [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'kalaka-support-node',
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'parent' => ['type' => 'integer'],
                    'ancestors' => ['type' => 'array', 'items' => $reference],
                ],
            ],
        ];
    }
}
