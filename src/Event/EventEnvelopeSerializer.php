<?php

declare(strict_types=1);

namespace Calipso\Sdk\Event;

use Calipso\Sdk\Exception\InvalidEvent;
use stdClass;

final class EventEnvelopeSerializer
{
    private const MAX_ENVELOPE_BYTES = 262144;

    /** @return array<string, mixed> */
    public function toArray(Event $event): array
    {
        $entities = $event->entities();
        usort($entities, static function (EntityReference $left, EntityReference $right): int {
            return [$left->type(), $left->id()] <=> [$right->type(), $right->id()];
        });

        $envelope = [
            'eventId' => $event->eventId(),
            'type' => $event->type(),
            'occurredAt' => $event->occurredAt()->format('Y-m-d\TH:i:s.u\Z'),
            'entities' => array_map(static function (EntityReference $entity): array {
                return [
                    'type' => $entity->type(),
                    'id' => $entity->id(),
                ];
            }, $entities),
        ];

        if ($event->correlationId() !== null) {
            $envelope['correlationId'] = $event->correlationId();
        }

        $payload = self::sortJsonValue($event->payload());
        $envelope['payload'] = $payload === [] ? new stdClass() : $payload;

        return $envelope;
    }

    public function serialize(Event $event): string
    {
        $json = json_encode(
            $this->toArray($event),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
            512
        );

        if (strlen($json) > self::MAX_ENVELOPE_BYTES) {
            throw new InvalidEvent('Serialized event envelope exceeds the 256 KiB protocol limit.');
        }

        return $json;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function sortJsonValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            return array_map(static function ($item) {
                return self::sortJsonValue($item);
            }, $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortJsonValue($item);
        }

        return $value;
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
}
