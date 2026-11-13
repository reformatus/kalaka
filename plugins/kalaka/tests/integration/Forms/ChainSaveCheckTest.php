<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Module;

/** Branch mistakes are definition problems, so the save check keeps a form with one from being published. */
final class ChainSaveCheckTest extends FormsTestCase
{
    /** @param array<string, mixed>|null $condition */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        return '<!-- wp:kalaka/' . $name . ($condition === null ? '' : ' '
            . wp_json_encode(['condition' => $condition]))
            . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    /** @return array<string, mixed> */
    private function answered(string $key): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'empty', 'not' => true]]];
    }

    private function note(string $key): string
    {
        return $this->field('text', $key);
    }

    /** The vetoed save's notice; the form stays a draft. */
    private function refusal(string $content): string
    {
        $user = self::factory()->user->create();
        wp_set_current_user($user);
        $id = $this->insertChecked($this->slotFields() . $content);
        self::assertSame('draft', get_post_status($id));
        $notice = get_transient(DefinitionCheck::NOTICE . $user);
        self::assertIsString($notice);

        return $notice;
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenChains(): iterable
    {
        yield 'Különben with a paragraph before it' => ['else-after-paragraph', 'orphan_branch („kalaka/else”)'];
        yield 'Különben ha without a Ha' => ['lone-else-if', 'orphan_branch („kalaka/else-if”)'];
        yield 'Ha with no condition' => ['empty-if', 'invalid_condition („kalaka/if”)'];
        yield 'Ha with an empty condition' => ['empty-object', 'invalid_condition („kalaka/if”)'];
        yield 'a condition outside the schema' => ['bad-operator', 'invalid_condition („kalaka/if”)'];
    }

    /** @dataProvider brokenChains */
    public function testABrokenChainCannotBePublished(string $case, string $named): void
    {
        $content = $this->note('note') . match ($case) {
            'else-after-paragraph' => $this->branch('if', $this->answered('note'), $this->note('a'))
                . '<!-- wp:paragraph --><p>Köztes</p><!-- /wp:paragraph -->'
                . $this->branch('else', null, $this->note('b')),
            'lone-else-if' => $this->branch('else-if', $this->answered('note'), $this->note('a')),
            'empty-if' => $this->branch('if', null, $this->note('a')),
            'empty-object' => $this->branch('if', [], $this->note('a')),
            'bad-operator' => $this->branch(
                'if',
                ['match' => 'all', 'items' => [['key' => 'note', 'operator' => 'startsWith', 'value' => 'x']]],
                $this->note('a')
            ),
        };

        self::assertStringContainsString(
            'Az űrlapot így nem lehet menteni: ' . $named . '.',
            $this->refusal($content)
        );
    }

    public function testTheEditorGetsTheSameRefusal(): void
    {
        (new Module())->registerMeta();
        $user = self::factory()->user->create();
        $wpUser = get_userdata($user);
        self::assertInstanceOf(\WP_User::class, $wpUser);
        $wpUser->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($user);

        $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_form');
        $request->set_body_params([
            'title' => 'Regisztráció',
            'status' => 'publish',
            'content' => $this->slotFields() . $this->branch('else', null, $this->note('b')),
            'meta' => ['kalaka_event_id' => 7, 'kalaka_placement' => 'registration'],
        ]);
        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('Az űrlapot így nem lehet menteni: orphan_branch („kalaka/else”).', $data['message']);
    }

    public function testAWorkingChainIsPublished(): void
    {
        $id = $this->insertChecked(
            $this->slotFields() . $this->note('note')
                . $this->branch('if', $this->answered('note'), $this->note('a'))
                . $this->branch('else', null, $this->note('b'))
        );

        self::assertCount(5, $this->forms->definition($this->readForm($id))->fields);
    }
}
