<?php

declare(strict_types=1);

namespace Calipso\Sdk\Http;

final class HttpResponse
{
    /** @var int */
    private $statusCode;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /** @param array<string, string> $headers */
    public function __construct(int $statusCode, array $headers = [], string $body = '')
    {
        $this->statusCode = $statusCode;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
        $this->body = $body;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }
}
