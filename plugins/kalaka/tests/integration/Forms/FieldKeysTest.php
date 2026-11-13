<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\KeyDerivation;

/** Keys and option values come from the visible text, set on save (events-and-forms.md, "Fields"). */
final class FieldKeysTest extends FormsTestCase
{
    /** @param array<string, mixed> $attributes */
    private function unkeyed(string $type, string $label, array $attributes = []): string
    {
        return $this->block('kalaka/' . $type . '-field', $attributes + ['label' => $label]);
    }

    /** @return list<string> */
    private function keysOf(int $id): array
    {
        $definition = $this->forms->definition($this->readForm($id));
        self::assertInstanceOf(FormDefinition::class, $definition);

        return array_map(static fn ($field): string => $field->key, $definition->fields);
    }

    /** @return list<array<string, mixed>> the attributes of the field blocks in the stored content */
    private function storedFields(int $id): array
    {
        $post = get_post($id);
        self::assertInstanceOf(\WP_Post::class, $post);
        $fields = [];
        $walk = static function (array $blocks) use (&$walk, &$fields): void {
            foreach ($blocks as $block) {
                if (is_string($block['blockName']) && str_starts_with($block['blockName'], 'kalaka/')) {
                    $fields[] = $block['attrs'];
                }
                $walk($block['innerBlocks']);
            }
        };
        $walk(parse_blocks($post->post_content));

        return $fields;
    }

    /** @return iterable<string, array{string, string}> */
    public static function titles(): iterable
    {
        yield 'Hungarian accents' => ['Árvíztűrő tükörfúrógép', 'arvizturo_tukorfurogep'];
        yield 'punctuation and case' => ['Szülő / gondviselő neve?', 'szulo_gondviselo_neve'];
        yield 'markup and entities' => ['<b>Pólóméret</b> &amp; fazon', 'polomeret_fazon'];
        yield 'leading digits dropped' => ['2026. évi tábor', 'evi_tabor'];
        yield 'German letters, the same whatever the site locale' => ['Größe', 'grose'];
        yield 'nothing usable' => ['?!', 'text'];
        yield 'too long' => [str_repeat('nagyon ', 20), rtrim(substr(str_repeat('nagyon_', 20), 0, 64), '_')];
    }

    /** @dataProvider titles */
    public function testAKeyIsDerivedFromTheTitle(string $title, string $key): void
    {
        self::assertSame($key, KeyDerivation::key($title, 'kalaka/text-field', []));
    }

    public function testACollisionGetsASuffixWithinThe64Characters(): void
    {
        self::assertSame('nev_1', KeyDerivation::key('Név', 'kalaka/name-field', ['nev' => true]));
        self::assertSame('nev_2', KeyDerivation::key('Név', 'kalaka/name-field', ['nev' => true, 'nev_1' => true]));
        $long = str_repeat('a', 64);
        self::assertSame(str_repeat('a', 62) . '_1', KeyDerivation::key($long, 'kalaka/text-field', [$long => true]));
        self::assertSame('email', KeyDerivation::key('', 'kalaka/email-field', []));
        self::assertSame('1_ejszaka', KeyDerivation::value('1 éjszaka', []));
        self::assertSame('option_1', KeyDerivation::value('', ['option' => true]));
    }

    public function testSavingAFormSetsMissingKeysInDocumentOrderAcrossNesting(): void
    {
        $id = $this->insertForm(
            $this->unkeyed('name', 'Név', ['displayName' => true])
                . $this->unkeyed('email', 'E-mail-cím', ['contactEmail' => true])
                . $this->wrapped($this->unkeyed('text', 'Név'))
                . $this->unkeyed('text', 'Név')
        );

        self::assertSame(['nev', 'e_mail_cim', 'nev_1', 'nev_2'], $this->keysOf($id));
    }

    public function testAnExistingKeyStaysWhenTheTitleIsRenamed(): void
    {
        $id = $this->insertForm($this->slotFields() . $this->unkeyed('text', 'Pólóméret'));
        self::assertSame(['name', 'email', 'polomeret'], $this->keysOf($id));

        $post = get_post($id);
        self::assertInstanceOf(\WP_Post::class, $post);
        wp_update_post([
            'ID' => $id,
            'post_content' => wp_slash(str_replace('Pólóméret', 'Póló mérete', $post->post_content)),
        ]);

        self::assertSame(['name', 'email', 'polomeret'], $this->keysOf($id));
        self::assertSame('Póló mérete', $this->storedFields($id)[2]['label']);
    }

    public function testAKeptKeyIsNeverTakenByANewField(): void
    {
        $id = $this->insertForm(
            $this->slotFields() . $this->field('text', 'polomeret') . $this->unkeyed('text', 'Pólóméret')
        );

        self::assertSame(['name', 'email', 'polomeret', 'polomeret_1'], $this->keysOf($id));
    }

    public function testARepeatedOrInvalidKeyIsReplacedLaterInTheDocument(): void
    {
        $id = $this->insertForm(
            $this->slotFields()
                . $this->field('text', 'note', ['label' => 'Megjegyzés'])
                . $this->field('text', 'note', ['label' => 'Megjegyzés'])
                . $this->field('date', 'Bad Key', ['label' => 'Érkezés'])
        );

        self::assertSame(['name', 'email', 'note', 'megjegyzes', 'erkezes'], $this->keysOf($id));
    }

    public function testOptionValuesAreDerivedFromTheirLabelsWithinTheField(): void
    {
        $options = [
            ['label' => 'Kicsi (S)'],
            ['value' => 'kozepes', 'label' => 'Közepes'],
            ['label' => 'Közepes'],
            ['value' => 'Bad', 'label' => 'Nagy'],
            ['value' => 'kozepes', 'label' => 'Óriás'],
        ];
        $id = $this->insertForm($this->slotFields() . $this->unkeyed('choice', 'Méret', ['options' => $options]));

        self::assertSame(
            ['kicsi_s', 'kozepes', 'kozepes_1', 'nagy', 'orias'],
            array_column($this->storedFields($id)[2]['options'], 'value')
        );
        self::assertSame('meret', $this->storedFields($id)[2]['key']);
        self::assertSame(['name', 'email', 'meret'], $this->keysOf($id));
    }

    public function testContentWithEveryKeySetIsStoredUnchanged(): void
    {
        $content = '<!-- wp:paragraph --><p>Bevezető</p><!-- /wp:paragraph -->' . $this->slotFields();
        $id = $this->insertForm($content);

        $post = get_post($id);
        self::assertInstanceOf(\WP_Post::class, $post);
        self::assertSame($content, $post->post_content);
    }

    public function testOtherPostTypesAreLeftAlone(): void
    {
        $content = $this->unkeyed('text', 'Név');
        $id = self::factory()->post->create(['post_content' => $content]);

        $post = get_post($id);
        self::assertInstanceOf(\WP_Post::class, $post);
        self::assertSame($content, $post->post_content);
    }

    private function wrapped(string $inner): string
    {
        return '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
    }
}
