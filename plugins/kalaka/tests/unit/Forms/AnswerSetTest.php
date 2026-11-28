<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/** S74: the answers by key, as a submission and a draft store them. */
final class AnswerSetTest extends TestCase
{
    public function testItIsStoredAsAJsonObjectAndReadBack(): void
    {
        $set = new AnswerSet(['name' => 'Árvíztűrő Tükörfúrógép', 'sizes' => ['s', 'm'], 'ok' => true]);

        self::assertSame('{"name":"Árvíztűrő Tükörfúrógép","sizes":["s","m"],"ok":true}', $set->toJson());
        self::assertSame($set->answers, AnswerSet::fromJson($set->toJson())->answers);
        self::assertSame('{}', (new AnswerSet())->toJson());
        self::assertTrue(AnswerSet::fromJson('{}')->isEmpty());
    }

    public function testTheOtherSetsAnswersAreWrittenOverAndTheRestKept(): void
    {
        $draft = new AnswerSet(['name' => 'Teszt Anna', 'note' => 'saját']);

        self::assertSame(
            ['name' => 'Teszt Anna', 'note' => 'a kvízből', 'size' => 'm'],
            $draft->overwrittenBy(new AnswerSet(['note' => 'a kvízből', 'size' => 'm']))->answers
        );
    }

    public function testStoredTextThatIsNotAnObjectIsASystemFailure(): void
    {
        $this->expectException(SystemFailure::class);

        AnswerSet::fromJson('"text"');
    }
}
