<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests\Event;

use Calipso\Sdk\Client;
use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Delivery\DeliveryResult;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Event\EventEnvelopeSerializer;
use Calipso\Sdk\Exception\InvalidEvent;
use Calipso\Sdk\Transport\TransportInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventBuilderTest extends TestCase
{
    public function testBasicEventCreationAndTransportDelivery(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport);

        $result = $client->event('payment.created')
            ->entity('payment', 'pay-42')
            ->payload(['amount' => '125.50'])
            ->send();

        $event = $transport->lastEvent();
        self::assertSame(DeliveryResult::ACCEPTED, $result->status());
        self::assertSame($event->eventId(), $result->eventId());
        self::assertSame('payment.created', $event->type());
        self::assertSame(['amount' => '125.50'], $event->payload());
    }

    public function testMultipleEntitiesAndCorrelationId(): void
    {
        $transport = new RecordingTransport();

        $this->client($transport)
            ->event('payment.created')
            ->entity('payment', 'pay-42')
            ->entity('customer', 'customer-7')
            ->correlation('checkout-9001')
            ->send();

        $event = $transport->lastEvent();
        self::assertCount(2, $event->entities());
        self::assertSame('checkout-9001', $event->correlationId());
    }

    public function testExplicitEventIdAndOccurredAtArePreserved(): void
    {
        $transport = new RecordingTransport();
        $occurredAt = new DateTimeImmutable('2026-08-12T10:00:00+02:00');

        $result = $this->client($transport)
            ->event('payment.created')
            ->eventId('business-event-42')
            ->occurredAt($occurredAt)
            ->send();

        $event = $transport->lastEvent();
        self::assertSame('business-event-42', $result->eventId());
        self::assertSame('business-event-42', $event->eventId());
        self::assertSame(
            '2026-08-12T08:00:00.000000Z',
            $event->occurredAt()->format('Y-m-d\TH:i:s.u\Z')
        );
    }

    public function testBlankEventTypeIsRejectedBeforeTransport(): void
    {
        $transport = new RecordingTransport();

        $this->expectException(InvalidEvent::class);
        $this->client($transport)->event('  ');
    }

    public function testInvalidEntityIsRejectedBeforeTransport(): void
    {
        $transport = new RecordingTransport();

        try {
            $this->client($transport)
                ->event('payment.created')
                ->entity('', 'pay-42');
            self::fail('Expected invalid entity to be rejected.');
        } catch (InvalidEvent $exception) {
            self::assertSame([], $transport->events());
        }
    }

    public function testPayloadPassesThroughWithoutCallerMutation(): void
    {
        $transport = new RecordingTransport();
        $payload = ['nested' => ['second' => 2, 'first' => 1]];
        $original = $payload;

        $this->client($transport)
            ->event('payment.created')
            ->payload($payload)
            ->send();

        $event = $transport->lastEvent();
        self::assertSame($original, $payload);
        self::assertSame($original, $event->payload());
    }

    public function testTransportReceivesExpectedProtocolEnvelope(): void
    {
        $transport = new RecordingTransport();

        $this->client($transport)
            ->event('payment.created')
            ->entity('payment', 'pay-42')
            ->correlation('checkout-9001')
            ->payload(['currency' => 'EUR'])
            ->eventId('event-42')
            ->occurredAt(new DateTimeImmutable('2026-08-12T10:00:00Z'))
            ->send();

        self::assertSame(
            '{"eventId":"event-42","type":"payment.created","occurredAt":"2026-08-12T10:00:00.000000Z","entities":[{"type":"payment","id":"pay-42"}],"correlationId":"checkout-9001","payload":{"currency":"EUR"}}',
            (new EventEnvelopeSerializer())->serialize($transport->lastEvent())
        );
    }

    public function testRepeatedSendReusesLogicalEventIdentity(): void
    {
        $transport = new RecordingTransport();
        $builder = $this->client($transport)->event('payment.created');

        $first = $builder->send();
        $firstEvent = $transport->lastEvent();
        $second = $builder->send();
        $secondEvent = $transport->lastEvent();

        self::assertSame($first->eventId(), $second->eventId());
        self::assertSame($firstEvent, $secondEvent);
        self::assertCount(2, $transport->events());
    }

    private function client(TransportInterface $transport): Client
    {
        return new Client(
            new ClientConfiguration('https://api.example.com', 'project-credential'),
            $transport
        );
    }
}

final class RecordingTransport implements TransportInterface
{
    /** @var list<Event> */
    private $events = [];

    public function send(Event $event): DeliveryResult
    {
        $this->events[] = $event;

        return DeliveryResult::accepted($event->eventId());
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->events;
    }

    public function lastEvent(): Event
    {
        $event = end($this->events);
        if (!$event instanceof Event) {
            throw new \LogicException('No event was recorded.');
        }

        return $event;
    }
}
