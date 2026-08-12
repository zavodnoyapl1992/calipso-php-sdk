<?php

declare(strict_types=1);

namespace Calipso\Sdk\Http;

use InvalidArgumentException;

final class HttpRequest
{
    /** @var non-empty-string */
    private $method;

    /** @var non-empty-string */
    private $url;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /** @var float */
    private $connectTimeout;

    /** @var float */
    private $requestTimeout;

    /** @param array<string, string> $headers */
    public function __construct(
        string $method,
        string $url,
        array $headers,
        string $body,
        float $connectTimeout,
        float $requestTimeout
    ) {
        if ($method === '' || $url === '') {
            throw new InvalidArgumentException('HTTP request method and URL must not be empty.');
        }

        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->connectTimeout = $connectTimeout;
        $this->requestTimeout = $requestTimeout;
    }

    /** @return non-empty-string */
    public function method(): string
    {
        return $this->method;
    }

    /** @return non-empty-string */
    public function url(): string
    {
        return $this->url;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function requestTimeout(): float
    {
        return $this->requestTimeout;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => '[REDACTED]',
            'body' => '[REDACTED]',
            'connectTimeout' => $this->connectTimeout,
            'requestTimeout' => $this->requestTimeout,
        ];
    }
}
