<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Submission\Submission;

/** AC10: frozen submissions. */
final class SubmissionRepositoryTest extends RegistrationsTestCase
{
    public function testAnInsertedSubmissionReadsBackAndTheStoredJsonHoldsUnescapedCharacters(): void
    {
        $key = new RegistrationKey(5, '12A3');
        $answers = ['name' => 'Árvíztűrő Tükörfúrógép', 'age' => 12, 'choices' => ['a', 'b/c'], 'note' => null];
        $touched = null;

        $this->inRun(function (array $input, Context $context) use ($key, $answers, &$touched): void {
            $this->lockRegistrations($context, $key);
            $this->submissions->insert(
                new Submission($key, 1, 10, 3, $answers, $this->utc('2026-11-30 08:00:00.250000')),
                $context
            );
            $touched = $context->touchedRegistrations();
        });

        $found = $this->submissions->find(['eventId' => 5, 'code' => '12A3', 'number' => 1]);
        self::assertInstanceOf(Submission::class, $found);
        self::assertSame($answers, $found->answers);
        self::assertSame(10, $found->formId);
        self::assertSame(3, $found->formVersion);
        self::assertSame('2026-11-30 08:00:00.250000', $found->submittedAt->format('Y-m-d H:i:s.u'));

        $row = $this->rowOf('kalaka_submissions', 5, '12A3');
        self::assertSame(
            '{"name":"Árvíztűrő Tükörfúrógép","age":12,"choices":["a","b/c"],"note":null}',
            $row['answers'] ?? null
        );
        self::assertEquals([$key], $touched);

        $entry = $this->onlyEntry();
        self::assertSame(['registration/5/12A3'], $this->subjectsOf($entry['id']));
        self::assertSame('[]', $entry['changes']);
    }

    public function testNoAnswersAreStoredAsAnEmptyObject(): void
    {
        $this->inRun(function (array $input, Context $context): void {
            $this->lockRegistrations($context, new RegistrationKey(5, '12A3'));
            $this->submissions->insert(
                new Submission(new RegistrationKey(5, '12A3'), 1, 10, 1, [], $this->utc('2026-11-30 08:00:00')),
                $context
            );
        });

        self::assertSame('{}', $this->rowOf('kalaka_submissions', 5, '12A3')['answers'] ?? null);
        self::assertSame([], $this->submissions->find(['eventId' => 5, 'code' => '12A3', 'number' => 1])?->answers);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unencodable(): iterable
    {
        yield 'invalid UTF-8' => [['name' => "\xB1\x31"]];
        yield 'NAN' => [['score' => NAN]];
    }

    /**
     * @param array<string, mixed> $answers
     * @dataProvider unencodable
     */
    public function testAnswersThatCannotBeEncodedAreASystemFailureAndStoreNothing(array $answers): void
    {
        global $wpdb;

        try {
            $this->inRun(function (array $input, Context $context) use ($answers): void {
                $this->submissions->insert(
                    new Submission(
                        new RegistrationKey(5, '12A3'),
                        1,
                        10,
                        1,
                        $answers,
                        $this->utc('2026-11-30 08:00:00')
                    ),
                    $context
                );
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
        }

        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_submissions"));
    }
}
