<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests\Event;

use Calipso\Sdk\Event\EntityReference;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Exception\InvalidEvent;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventValidationTest extends TestCase
{
    /** @dataProvider invalidEventTypeProvider */
    public function testInvalidEventTypeIsRejected(string $type): void
    {
        $this->expectException(InvalidEvent::class);

        new Event($type, [], []);
    }

    /** @return iterable<string, array{string}> */
    public function invalidEventTypeProvider(): iterable
    {
        yield 'blank' => [''];
        yield 'not dot separated' => ['payment'];
        yield 'uppercase' => ['Payment.Approved'];
        yield 'spaces' => ['payment approved'];
    }

    public function testDuplicateEntitiesAreRejected(): void
    {
        $this->expectException(InvalidEvent::class);

        new Event('payment.approved', [
            new EntityReference('payment', 'pay-42'),
            new EntityReference('payment', 'pay-42'),
        ], []);
    }

    public function testReservedPayloadPropertyIsRejected(): void
    {
        $this->expectException(InvalidEvent::class);

        new Event('payment.approved', [], ['_calipso' => ['secret' => true]]);
    }

    public function testNonJsonPayloadValueIsRejected(): void
    {
        $this->expectException(InvalidEvent::class);

        new Event('payment.approved', [], ['value' => new \stdClass()]);
    }

    public function testTopLevelPayloadArrayIsRejected(): void
    {
        $this->expectException(InvalidEvent::class);

        new Event('payment.approved', [], ['first', 'second']);
    }

    public function testTimestampMoreThanFiveMinutesInFutureIsRejected(): void
    {
        $this->expectException(InvalidEvent::class);

        new Event('payment.approved', [], [], null, null, new DateTimeImmutable('+6 minutes'));
    }
}
