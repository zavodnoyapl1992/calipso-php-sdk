<?php

declare(strict_types=1);

namespace Calipso\Sdk\Delivery;

use InvalidArgumentException;

final class DeliveryResult
{
    public const ACCEPTED = 'accepted';
    public const DUPLICATE = 'duplicate';
    public const REJECTED = 'rejected';
    public const UNAVAILABLE = 'unavailable';
    public const QUEUE_FULL = 'queue_full';

    /** @var string */
    private $status;

    /** @var string */
    private $eventId;

    /** @var string|null */
    private $errorCode;

    /** @var int|null */
    private $retryAfterSeconds;

    private function __construct(
        string $status,
        string $eventId,
        ?string $errorCode = null,
        ?int $retryAfterSeconds = null
    ) {
        $eventId = trim($eventId);
        if ($eventId === '') {
            throw new InvalidArgumentException('Delivery result event ID must not be blank.');
        }

        $this->status = $status;
        $this->eventId = $eventId;
        $this->errorCode = $errorCode;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public static function accepted(string $eventId): self
    {
        return new self(self::ACCEPTED, $eventId);
    }

    public static function duplicate(string $eventId): self
    {
        return new self(self::DUPLICATE, $eventId);
    }

    public static function rejected(string $eventId, string $errorCode): self
    {
        return new self(self::REJECTED, $eventId, self::validateErrorCode($errorCode));
    }

    public static function unavailable(
        string $eventId,
        ?string $errorCode = null,
        ?int $retryAfterSeconds = null
    ): self {
        if ($retryAfterSeconds !== null && $retryAfterSeconds < 1) {
            throw new InvalidArgumentException('Retry-after seconds must be greater than zero.');
        }

        return new self(
            self::UNAVAILABLE,
            $eventId,
            self::validateOptionalErrorCode($errorCode),
            $retryAfterSeconds
        );
    }

    public static function queueFull(string $eventId): self
    {
        return new self(self::QUEUE_FULL, $eventId, self::QUEUE_FULL);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::ACCEPTED || $this->status === self::DUPLICATE;
    }

    private static function validateOptionalErrorCode(?string $errorCode): ?string
    {
        return $errorCode === null ? null : self::validateErrorCode($errorCode);
    }

    private static function validateErrorCode(string $errorCode): string
    {
        $errorCode = trim($errorCode);
        if ($errorCode === '') {
            throw new InvalidArgumentException('Delivery error code must not be blank.');
        }

        return $errorCode;
    }
}
