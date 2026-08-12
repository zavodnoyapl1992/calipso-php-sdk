<?php

declare(strict_types=1);

namespace Calipso\Sdk\Event;

use Calipso\Sdk\Exception\InvalidEvent;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Event
{
    /** @var string */
    private $eventId;

    /** @var string */
    private $type;

    /** @var DateTimeImmutable */
    private $occurredAt;

    /** @var list<EntityReference> */
    private $entities;

    /** @var array<array-key, mixed> */
    private $payload;

    /** @var string|null */
    private $correlationId;

    /**
     * @param array<mixed>          $entities
     * @param array<mixed>          $payload
     */
    public function __construct(
        string $type,
        array $entities,
        array $payload,
        ?string $correlationId = null,
        ?string $eventId = null,
        ?DateTimeInterface $occurredAt = null
    ) {
        $this->type = self::validateType($type);
        $this->entities = self::validateEntities($entities);
        $this->payload = self::validatePayload($payload);
        $this->correlationId = self::validateOptionalOpaqueId($correlationId, 'Correlation ID');
        $this->eventId = $eventId === null
            ? self::generateEventId()
            : self::validateOpaqueId($eventId, 'Event ID');
        $this->occurredAt = self::normalizeTimestamp($occurredAt);
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return list<EntityReference> */
    public function entities(): array
    {
        return $this->entities;
    }

    /** @return array<array-key, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    private static function validateType(string $type): string
    {
        $type = trim($type);

        if (
            strlen($type) < 3
            || strlen($type) > 180
            || preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)+$/D', $type) !== 1
        ) {
            throw new InvalidEvent('Event type must be a lowercase dot-separated identifier between 3 and 180 bytes.');
        }

        return $type;
    }

    /**
     * @param array<mixed> $entities
     *
     * @return list<EntityReference>
     */
    private static function validateEntities(array $entities): array
    {
        $seen = [];
        $validated = [];

        foreach ($entities as $entity) {
            if (!$entity instanceof EntityReference) {
                throw new InvalidEvent('Every entity must be an EntityReference.');
            }

            $key = $entity->type() . "\0" . $entity->id();
            if (isset($seen[$key])) {
                throw new InvalidEvent('Duplicate entity references are not allowed.');
            }

            $seen[$key] = true;
            $validated[] = $entity;
        }

        return $validated;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function validatePayload(array $payload): array
    {
        if ($payload !== [] && self::isList($payload)) {
            throw new InvalidEvent('Payload must be a JSON object.');
        }

        if (array_key_exists('_calipso', $payload)) {
            throw new InvalidEvent('The reserved payload property "_calipso" is not allowed.');
        }

        self::validateJsonValue($payload, 1);

        return $payload;
    }

    /** @param mixed $value */
    private static function validateJsonValue($value, int $depth): void
    {
        if ($depth > 512) {
            throw new InvalidEvent('Payload exceeds the maximum JSON depth of 512.');
        }

        if ($value === null || is_string($value) || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidEvent('Payload numbers must be finite.');
            }

            return;
        }

        if (!is_array($value)) {
            throw new InvalidEvent('Payload must contain only JSON-compatible values.');
        }

        foreach ($value as $item) {
            self::validateJsonValue($item, $depth + 1);
        }
    }

    private static function validateOptionalOpaqueId(?string $value, string $name): ?string
    {
        return $value === null ? null : self::validateOpaqueId($value, $name);
    }

    private static function validateOpaqueId(string $value, string $name): string
    {
        $value = trim($value);
        $length = preg_match_all('/./us', $value, $matches);

        if ($length === false || $length < 1 || $length > 180) {
            throw new InvalidEvent(sprintf('%s must contain between 1 and 180 characters.', $name));
        }

        return $value;
    }

    private static function normalizeTimestamp(?DateTimeInterface $occurredAt): DateTimeImmutable
    {
        if ($occurredAt === null) {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $normalized = (new DateTimeImmutable($occurredAt->format('Y-m-d H:i:s.uP')))
            ->setTimezone(new DateTimeZone('UTC'));

        if ($normalized > new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'))) {
            throw new InvalidEvent('Occurred-at timestamp cannot be more than five minutes in the future.');
        }

        return $normalized;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        $expectedKey = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expectedKey) {
                return false;
            }

            ++$expectedKey;
        }

        return true;
    }

    private static function generateEventId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
