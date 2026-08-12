<?php

declare(strict_types=1);

namespace Calipso\Sdk\Configuration;

use Calipso\Sdk\Exception\InvalidConfiguration;

final class ClientConfiguration
{
    /** @var string */
    private $endpoint;

    /** @var string */
    private $apiKey;

    /** @var TransportConfiguration */
    private $transport;

    /** @var string */
    private $failurePolicy;

    /** @var string|null */
    private $environment;

    /** @var string|null */
    private $service;

    public function __construct(
        string $endpoint,
        string $apiKey,
        ?TransportConfiguration $transport = null,
        string $failurePolicy = FailurePolicy::IGNORE,
        ?string $environment = null,
        ?string $service = null
    ) {
        $this->endpoint = self::validateEndpoint($endpoint);
        self::validateApiKey($apiKey);

        if (!FailurePolicy::isValid($failurePolicy)) {
            throw new InvalidConfiguration('Unsupported failure policy.');
        }

        $this->apiKey = $apiKey;
        $this->transport = $transport ?? TransportConfiguration::directHttp();
        $this->failurePolicy = $failurePolicy;
        $this->environment = self::normalizeMetadata($environment, 'Environment');
        $this->service = self::normalizeMetadata($service, 'Service');
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function transport(): TransportConfiguration
    {
        return $this->transport;
    }

    public function failurePolicy(): string
    {
        return $this->failurePolicy;
    }

    public function environment(): ?string
    {
        return $this->environment;
    }

    public function service(): ?string
    {
        return $this->service;
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'apiKey' => '[REDACTED]',
            'transportMode' => $this->transport->mode(),
            'failurePolicy' => $this->failurePolicy,
            'environment' => $this->environment,
            'service' => $this->service,
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'ClientConfiguration(endpoint=%s, apiKey=[REDACTED], transport=%s, failurePolicy=%s)',
            $this->endpoint,
            $this->transport->mode(),
            $this->failurePolicy
        );
    }

    private static function validateEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        $parts = parse_url($endpoint);

        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidConfiguration('Endpoint must be a valid HTTP or HTTPS base URL.');
        }

        return rtrim($endpoint, '/');
    }

    private static function validateApiKey(string $apiKey): void
    {
        if (trim($apiKey) === '') {
            throw new InvalidConfiguration('API key must not be blank.');
        }
    }

    private static function normalizeMetadata(?string $value, string $name): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidConfiguration(sprintf('%s metadata must not be blank.', $name));
        }

        return $value;
    }
}
