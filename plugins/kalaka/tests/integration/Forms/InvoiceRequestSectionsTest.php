<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;

/**
 * S26: an invoice request needs no mechanism of its own. The participant's billing section and, when a
 * congregation pays a part, the congregation's are ordinary fields in conditional sections (S76): each shows
 * only when its request is made, its required fields are required only then, and a hidden section's answers
 * are dropped.
 */
final class InvoiceRequestSectionsTest extends FormsTestCase
{
    private const YES_NO = [['value' => 'igen', 'label' => 'Igen'], ['value' => 'nem', 'label' => 'Nem']];

    /** @param array<string, mixed>|null $condition */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        return '<!-- wp:kalaka/' . $name . ($condition === null ? '' : ' '
            . wp_json_encode(['condition' => $condition]))
            . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    /**
     * @param list<array{0: string, 1: string}> $equals field key and option value pairs
     * @return array<string, mixed>
     */
    private static function when(string $match, array $equals): array
    {
        return [
            'match' => $match,
            'items' => array_map(
                static fn (array $pair): array => ['key' => $pair[0], 'operator' => 'equals', 'value' => $pair[1]],
                $equals
            ),
        ];
    }

    private function invoiceForm(): FormDefinition
    {
        $support = [
            ['value' => 'nincs', 'label' => 'Nincs'],
            ['value' => 'megosztott', 'label' => 'Megosztott'],
            ['value' => 'teljes', 'label' => 'Teljes'],
        ];
        $content = $this->field('choice', 'tamogatas', ['options' => $support, 'required' => true])
            . $this->field('choice', 'szamla', ['options' => self::YES_NO, 'required' => true])
            . $this->branch(
                'if',
                self::when('all', [['szamla', 'igen']]),
                $this->field('text', 'szamla_nev', ['required' => true])
                . $this->field('text', 'szamla_cim', ['required' => true])
            )
            . $this->branch(
                'if',
                self::when('any', [['tamogatas', 'megosztott'], ['tamogatas', 'teljes']]),
                $this->field('choice', 'gy_szamla', ['options' => self::YES_NO, 'required' => true])
                . $this->branch(
                    'if',
                    self::when('all', [['gy_szamla', 'igen']]),
                    $this->field('text', 'gy_szamla_nev', ['required' => true])
                    . $this->field('text', 'gy_szamla_cim', ['required' => true])
                )
            );

        return $this->forms->definition($this->readForm($this->slotForm($content)));
    }

    /** @return array<string, mixed> */
    private static function slot(): array
    {
        return ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];
    }

    private function eventFacts(): ValueContext
    {
        return new ValueContext([
            EventValues::NAME => Value::text('Teszt tábor', 'Teszt tábor'),
            EventValues::REGISTRATION_OPENS => Value::none(ValueType::Date),
            EventValues::REGISTRATION_CLOSES => Value::none(ValueType::Date),
            EventValues::START => Value::date('2027-07-10', '2027-07-10'),
            EventValues::END => Value::none(ValueType::Date),
        ]);
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, string>
     */
    private function errorsOf(FormDefinition $definition, array $answers): array
    {
        try {
            $this->forms->validate($definition, $answers);
        } catch (InvalidAnswers $e) {
            return $e->fields;
        }
        self::fail('The answers were accepted.');
    }

    public function testEachBillingSectionShowsOnlyWhenItsRequestIsMade(): void
    {
        $form = $this->invoiceForm();
        $billing = ['szamla_nev', 'szamla_cim', 'gy_szamla', 'gy_szamla_nev', 'gy_szamla_cim'];
        $shown = function (array $answers) use ($form, $billing): array {
            $availability = $this->forms->availability($form, self::slot() + $answers, $this->eventFacts());

            return array_values(array_filter($billing, static fn (string $key): bool => $availability->shows($key)));
        };

        self::assertSame([], $shown(['tamogatas' => 'nincs', 'szamla' => 'nem']));
        self::assertSame(['szamla_nev', 'szamla_cim'], $shown(['tamogatas' => 'nincs', 'szamla' => 'igen']));
        self::assertSame(['gy_szamla'], $shown(['tamogatas' => 'teljes', 'szamla' => 'nem']));
        self::assertSame(
            ['szamla_nev', 'szamla_cim', 'gy_szamla', 'gy_szamla_nev', 'gy_szamla_cim'],
            $shown(['tamogatas' => 'megosztott', 'szamla' => 'igen', 'gy_szamla' => 'igen'])
        );
    }

    public function testAShownSectionIsRequiredAndAHiddenOnesAnswersAreDropped(): void
    {
        $form = $this->invoiceForm();

        self::assertSame(
            ['gy_szamla_nev', 'gy_szamla_cim'],
            array_keys($this->errorsOf($form, self::slot() + [
                'tamogatas' => 'teljes',
                'szamla' => 'nem',
                'gy_szamla' => 'igen',
            ]))
        );

        $validated = $this->forms->validate($form, self::slot() + [
            'tamogatas' => 'nincs',
            'szamla' => 'igen',
            'szamla_nev' => 'Teszt Elek',
            'szamla_cim' => '1234 Teszthely, Teszt utca 1.',
            'gy_szamla' => 'igen',
            'gy_szamla_nev' => 'Teszt Gyülekezet',
        ]);

        self::assertSame(
            ['name', 'email', 'tamogatas', 'szamla', 'szamla_nev', 'szamla_cim'],
            array_keys($validated->stored())
        );
    }
}
