<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\PublishEvent;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The form page of a multi-page form (#167's pages): the navigation buttons and the context's pages. The
 * re-fetch with the page whose errors Tovább asks for is `FormRoutesTest`'s.
 */
final class FormPageNavigationTest extends FormsTestCase
{
    /** @param array<string, mixed> $attributes */
    private function page(string $inner, array $attributes = []): string
    {
        return '<!-- wp:kalaka/form-page' . ($attributes === [] ? '' : ' ' . wp_json_encode($attributes)) . ' -->'
            . $inner . '<!-- /wp:kalaka/form-page -->';
    }

    /** @return array<string, mixed> */
    private function when(string $key, string $value): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'equals', 'value' => $value]]];
    }

    private function mode(): string
    {
        return $this->field('choice', 'mode', ['required' => true, 'options' => [
            ['value' => 'camp', 'label' => 'Tábor'],
            ['value' => 'day', 'label' => 'Napközi'],
            ['value' => 'young', 'label' => 'Fiatal'],
        ]]);
    }

    /**
     * Page 0: the basics and the mode; page 1 for camp (a required room and a chain); page 2 for day;
     * page 3 terminal for young; page 4 for everyone.
     */
    private function content(): string
    {
        $note = ['match' => 'all', 'items' => [['key' => 'room', 'operator' => 'empty']]];

        return $this->page($this->slotFields() . $this->mode(), ['label' => 'Alapadatok'])
            . $this->page(
                $this->field('text', 'room', ['required' => true])
                . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $note]) . ' -->'
                . $this->field('text', 'why')
                . '<!-- /wp:kalaka/if -->',
                ['label' => 'Szállás', 'condition' => $this->when('mode', 'camp')]
            )
            . $this->page($this->field('text', 'arrival', ['required' => true]), [
                'condition' => $this->when('mode', 'day'),
            ])
            . $this->page('<!-- wp:paragraph --><p>Csak 14 éves kortól.</p><!-- /wp:paragraph -->', [
                'label' => 'Sajnáljuk',
                'terminal' => true,
                'condition' => $this->when('mode', 'young'),
            ])
            . $this->page($this->field('text', 'note'));
    }

    public function testEveryPageRendersHiddenWithItsNumberTitleAndTerminalMarkAndTheNavigationButtons(): void
    {
        $event = $this->create();
        $day = static fn (int $offset): string => Runner::current()->clock()->now()
            ->setTimezone(wp_timezone())->setTime(0, 0)
            ->modify(sprintf('%+d days', $offset))->format('Y-m-d H:i');
        $this->update($event, [
            'registration_opens_at' => $day(-1),
            'registration_closes_at' => $day(10),
            'starts_at' => $day(30),
            'ends_at' => $day(36),
        ]);
        $this->transition(new PublishEvent(), $event);
        $id = $this->insertForm($this->content(), ['kalaka_event_id' => $event]);

        $html = render_block([
            'blockName' => 'kalaka/form',
            'attrs' => ['formId' => $id],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);

        preg_match_all('/<div data-kalaka-page="[^>]*>/', $html, $pages);
        self::assertSame([
            '<div data-kalaka-page="0" hidden>',
            '<div data-kalaka-page="1" hidden>',
            '<div data-kalaka-page="2" hidden>',
            '<div data-kalaka-page="3" data-kalaka-terminal hidden>',
            '<div data-kalaka-page="4" hidden>',
        ], $pages[0]);
        $contains = static fn (string $part) => self::assertStringContainsString($part, $html);
        $contains('<div data-kalaka-page="0" hidden><h2 data-kalaka-page-title>Alapadatok</h2>');
        $contains('<div data-kalaka-page="2" hidden><div class="kalaka-field');
        $contains('data-kalaka-back data-wp-on--click="actions.back" hidden>Vissza</button>');
        $contains('data-kalaka-next data-wp-on--click="actions.next" hidden disabled>Tovább</button>');
        self::assertStringNotContainsString('kalakaPage', $html);
        // The fields of the pages that do not show for no answers are disabled.
        self::assertMatchesRegularExpression('/<input disabled type="text" id="kalaka-field-room" name="room"/', $html);

        $pattern = "/data-wp-context='([^']*)'/";
        self::assertSame(1, preg_match($pattern, $html, $match));
        $context = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([true, false, false, false, true], $context['pages']);
    }
}
