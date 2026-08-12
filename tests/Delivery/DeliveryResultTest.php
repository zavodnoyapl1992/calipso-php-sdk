<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests\Delivery;

use Calipso\Sdk\Delivery\DeliveryResult;
use PHPUnit\Framework\TestCase;

final class DeliveryResultTest extends TestCase
{
    /** @dataProvider resultProvider */
    public function testResultFactories(
        DeliveryResult $result,
        string $status,
        bool $success,
        ?string $errorCode
    ): void {
        self::assertSame('event-42', $result->eventId());
        self::assertSame($status, $result->status());
        self::assertSame($success, $result->isSuccess());
        self::assertSame($errorCode, $result->errorCode());
    }

    /** @return iterable<string, array{DeliveryResult, string, bool, string|null}> */
    public function resultProvider(): iterable
    {
        yield 'accepted' => [DeliveryResult::accepted('event-42'), DeliveryResult::ACCEPTED, true, null];
        yield 'duplicate' => [DeliveryResult::duplicate('event-42'), DeliveryResult::DUPLICATE, true, null];
        yield 'rejected' => [
            DeliveryResult::rejected('event-42', 'invalid_event'),
            DeliveryResult::REJECTED,
            false,
            'invalid_event',
        ];
        yield 'unavailable' => [
            DeliveryResult::unavailable('event-42', 'timeout'),
            DeliveryResult::UNAVAILABLE,
            false,
            'timeout',
        ];
        yield 'queue full' => [
            DeliveryResult::queueFull('event-42'),
            DeliveryResult::QUEUE_FULL,
            false,
            DeliveryResult::QUEUE_FULL,
        ];
    }
}
