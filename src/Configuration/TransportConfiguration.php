<?php

declare(strict_types=1);

namespace Calipso\Sdk\Configuration;

use Calipso\Sdk\Exception\InvalidConfiguration;

final class TransportConfiguration
{
    private const DEFAULT_REQUEST_TIMEOUT = 2.0;
    private const DEFAULT_CONNECT_TIMEOUT = 0.5;
    private const DEFAULT_MAX_ATTEMPTS = 2;
    private const DEFAULT_INITIAL_BACKOFF_MILLISECONDS = 100;
    private const DEFAULT_MAX_BACKOFF_MILLISECONDS = 1000;

    /** @var string */
    private $mode;

    /** @var float */
    private $requestTimeout;

    /** @var float */
    private $connectTimeout;

    /** @var int */
    private $maxAttempts;

    /** @var int */
    private $initialBackoffMilliseconds;

    /** @var int */
    private $maxBackoffMilliseconds;

    private function __construct(
        string $mode,
        float $requestTimeout,
        float $connectTimeout,
        int $maxAttempts,
        int $initialBackoffMilliseconds,
        int $maxBackoffMilliseconds
    ) {
        if ($requestTimeout <= 0 || !is_finite($requestTimeout)) {
            throw new InvalidConfiguration('Request timeout must be a finite number greater than zero.');
        }

        if ($connectTimeout <= 0 || !is_finite($connectTimeout)) {
            throw new InvalidConfiguration('Connect timeout must be a finite number greater than zero.');
        }

        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new InvalidConfiguration('Maximum delivery attempts must be between 1 and 10.');
        }

        if ($initialBackoffMilliseconds < 0 || $maxBackoffMilliseconds < $initialBackoffMilliseconds) {
            throw new InvalidConfiguration('Delivery backoff bounds are invalid.');
        }

        $this->mode = $mode;
        $this->requestTimeout = $requestTimeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxAttempts = $maxAttempts;
        $this->initialBackoffMilliseconds = $initialBackoffMilliseconds;
        $this->maxBackoffMilliseconds = $maxBackoffMilliseconds;
    }

    public static function directHttp(
        float $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        int $initialBackoffMilliseconds = self::DEFAULT_INITIAL_BACKOFF_MILLISECONDS,
        int $maxBackoffMilliseconds = self::DEFAULT_MAX_BACKOFF_MILLISECONDS
    ): self {
        return new self(
            TransportMode::DIRECT_HTTP,
            $requestTimeout,
            $connectTimeout,
            $maxAttempts,
            $initialBackoffMilliseconds,
            $maxBackoffMilliseconds
        );
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function requestTimeout(): float
    {
        return $this->requestTimeout;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function initialBackoffMilliseconds(): int
    {
        return $this->initialBackoffMilliseconds;
    }

    public function maxBackoffMilliseconds(): int
    {
        return $this->maxBackoffMilliseconds;
    }
}
