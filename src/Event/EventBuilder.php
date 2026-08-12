<?php

declare(strict_types=1);

namespace Calipso\Sdk\Event;

use Calipso\Sdk\Delivery\DeliveryResult;
use Calipso\Sdk\Exception\InvalidEvent;
use Calipso\Sdk\Transport\TransportInterface;
use DateTimeInterface;

final class EventBuilder
{
    /** @var TransportInterface */
    private $transport;

    /** @var string */
    private $type;

    /** @var list<EntityReference> */
    private $entities = [];

    /** @var array<mixed> */
    private $payload = [];

    /** @var string|null */
    private $correlationId;

    /** @var string|null */
    private $eventId;

    /** @var DateTimeInterface|null */
    private $occurredAt;

    /** @var Event|null */
    private $event;

    public function __construct(TransportInterface $transport, string $type)
    {
        if (trim($type) === '') {
            throw new InvalidEvent('Event type must not be blank.');
        }

        $this->transport = $transport;
        $this->type = $type;
    }

    public function entity(string $type, string $id): self
    {
        $this->assertMutable();
        $this->entities[] = new EntityReference($type, $id);

        return $this;
    }

    public function correlation(?string $correlationId): self
    {
        $this->assertMutable();
        $this->correlationId = $correlationId;

        return $this;
    }

    /** @param array<mixed> $payload */
    public function payload(array $payload): self
    {
        $this->assertMutable();
        $this->payload = $payload;

        return $this;
    }

    public function eventId(string $eventId): self
    {
        $this->assertMutable();
        $this->eventId = $eventId;

        return $this;
    }

    public function occurredAt(DateTimeInterface $occurredAt): self
    {
        $this->assertMutable();
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function send(): DeliveryResult
    {
        if ($this->event === null) {
            $this->event = new Event(
                $this->type,
                $this->entities,
                $this->payload,
                $this->correlationId,
                $this->eventId,
                $this->occurredAt
            );
        }

        return $this->transport->send($this->event);
    }

    private function assertMutable(): void
    {
        if ($this->event !== null) {
            throw new InvalidEvent('A sent event builder cannot be modified.');
        }
    }
}
