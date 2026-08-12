<?php

declare(strict_types=1);

namespace Calipso\Sdk\Configuration;

use Calipso\Sdk\Exception\InvalidConfiguration;

final class TransportConfiguration
{
    private const DEFAULT_REQUEST_TIMEOUT = 10.0;
    private const DEFAULT_CONNECT_TIMEOUT = 3.0;

    /** @var string */
    private $mode;

    /** @var float */
    private $requestTimeout;

    /** @var float */
    private $connectTimeout;

    private function __construct(string $mode, float $requestTimeout, float $connectTimeout)
    {
        if ($requestTimeout <= 0 || !is_finite($requestTimeout)) {
            throw new InvalidConfiguration('Request timeout must be a finite number greater than zero.');
        }

        if ($connectTimeout <= 0 || !is_finite($connectTimeout)) {
            throw new InvalidConfiguration('Connect timeout must be a finite number greater than zero.');
        }

        $this->mode = $mode;
        $this->requestTimeout = $requestTimeout;
        $this->connectTimeout = $connectTimeout;
    }

    public static function directHttp(
        float $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT
    ): self {
        return new self(TransportMode::DIRECT_HTTP, $requestTimeout, $connectTimeout);
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
}
