<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests\Event;

use Calipso\Sdk\Event\EntityReference;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Event\EventEnvelopeSerializer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testSerializationIsDeterministicAndMatchesProtocolEnvelope(): void
    {
        $event = new Event(
            'payment.approved',
            [
                new EntityReference('payment', 'pay-42'),
                new EntityReference('account', 'acc-7'),
            ],
            [
                'currency' => 'EUR',
                'details' => ['z' => 2, 'a' => 1],
                'amount' => '125.50',
            ],
            'checkout-9001',
            'event-42',
            new DateTimeImmutable('2026-08-12T12:14:32.123456+02:00')
        );
        $serializer = new EventEnvelopeSerializer();

        $expected = '{"eventId":"event-42","type":"payment.approved","occurredAt":"2026-08-12T10:14:32.123456Z","entities":[{"type":"account","id":"acc-7"},{"type":"payment","id":"pay-42"}],"correlationId":"checkout-9001","payload":{"amount":"125.50","currency":"EUR","details":{"a":1,"z":2}}}';

        self::assertSame($expected, $serializer->serialize($event));
        self::assertSame($expected, $serializer->serialize($event));
    }

    public function testGeneratedEventIdExistsAndRemainsStable(): void
    {
        $event = new Event('system.started', [], []);
        $serializer = new EventEnvelopeSerializer();
        $eventId = $event->eventId();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $eventId
        );
        self::assertSame($eventId, $event->eventId());
        self::assertSame($serializer->serialize($event), $serializer->serialize($event));
    }

    public function testExplicitExternalEventIdIsTrimmedAndPreserved(): void
    {
        $event = new Event('system.started', [], [], null, ' business-event-7 ');

        self::assertSame('business-event-7', $event->eventId());
    }

    public function testTimestampIsNormalizedToUtc(): void
    {
        $event = new Event(
            'system.started',
            [],
            [],
            null,
            'event-7',
            new DateTimeImmutable('2026-08-12T12:14:32.12+02:00')
        );

        self::assertSame(
            '2026-08-12T10:14:32.120000Z',
            (new EventEnvelopeSerializer())->toArray($event)['occurredAt']
        );
    }

    public function testCallerPayloadIsNotMutated(): void
    {
        $payload = ['z' => ['second' => 2, 'first' => 1], 'a' => 1];
        $original = $payload;
        $event = new Event('system.started', [], $payload);

        (new EventEnvelopeSerializer())->serialize($event);

        self::assertSame($original, $payload);
        self::assertSame($original, $event->payload());
    }

    public function testOptionalCorrelationIdIsOmittedAndEmptyPayloadIsAnObject(): void
    {
        $event = new Event('system.started', [], [], null, 'event-7');
        $json = (new EventEnvelopeSerializer())->serialize($event);

        self::assertSame(
            '{"eventId":"event-7","type":"system.started","occurredAt":"' .
            $event->occurredAt()->format('Y-m-d\TH:i:s.u\Z') .
            '","entities":[],"payload":{}}',
            $json
        );
        self::assertStringNotContainsString('correlationId', $json);
    }

    public function testEnvelopeContainsNoConfigurationOrCredentialData(): void
    {
        $credential = 'calipso_secret-project-credential';
        $event = new Event('system.started', [], ['status' => 'ready'], null, 'event-7');
        $json = (new EventEnvelopeSerializer())->serialize($event);

        self::assertStringNotContainsString($credential, $json);
        self::assertStringNotContainsString('projectId', $json);
        self::assertStringNotContainsString('sdk', $json);
        self::assertSame(
            ['eventId', 'type', 'occurredAt', 'entities', 'payload'],
            array_keys((new EventEnvelopeSerializer())->toArray($event))
        );
    }
}
