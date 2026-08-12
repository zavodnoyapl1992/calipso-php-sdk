<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests\Configuration;

use Calipso\Sdk\Client;
use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Configuration\FailurePolicy;
use Calipso\Sdk\Configuration\TransportConfiguration;
use Calipso\Sdk\Configuration\TransportMode;
use Calipso\Sdk\Exception\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class ClientConfigurationTest extends TestCase
{
    public function testValidMinimumConfigurationConstructsClient(): void
    {
        $configuration = new ClientConfiguration('https://api.example.com/', 'secret-key');
        $client = new Client($configuration);

        self::assertSame($configuration, $client->configuration());
        self::assertSame('https://api.example.com', $configuration->endpoint());
        self::assertSame(TransportMode::DIRECT_HTTP, $configuration->transport()->mode());
        self::assertSame(2.0, $configuration->transport()->requestTimeout());
        self::assertSame(0.5, $configuration->transport()->connectTimeout());
        self::assertSame(2, $configuration->transport()->maxAttempts());
        self::assertSame(100, $configuration->transport()->initialBackoffMilliseconds());
        self::assertSame(1000, $configuration->transport()->maxBackoffMilliseconds());
        self::assertSame(FailurePolicy::IGNORE, $configuration->failurePolicy());
    }

    /** @dataProvider blankApiKeyProvider */
    public function testBlankApiKeyIsRejected(string $apiKey): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('API key must not be blank.');

        new ClientConfiguration('https://api.example.com', $apiKey);
    }

    /** @return iterable<string, array{string}> */
    public function blankApiKeyProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n"];
    }

    /** @dataProvider invalidEndpointProvider */
    public function testInvalidEndpointIsRejected(string $endpoint): void
    {
        $this->expectException(InvalidConfiguration::class);

        new ClientConfiguration($endpoint, 'secret-key');
    }

    /** @return iterable<string, array{string}> */
    public function invalidEndpointProvider(): iterable
    {
        yield 'blank' => [''];
        yield 'relative' => ['/events'];
        yield 'unsupported scheme' => ['ftp://api.example.com'];
        yield 'query string' => ['https://api.example.com?key=value'];
        yield 'embedded credentials' => ['https://user:password@api.example.com'];
    }

    /** @dataProvider invalidTimeoutProvider */
    public function testInvalidTimeoutIsRejected(float $requestTimeout, float $connectTimeout): void
    {
        $this->expectException(InvalidConfiguration::class);

        TransportConfiguration::directHttp($requestTimeout, $connectTimeout);
    }

    /** @return iterable<string, array{float, float}> */
    public function invalidTimeoutProvider(): iterable
    {
        yield 'zero request timeout' => [0.0, 1.0];
        yield 'negative request timeout' => [-1.0, 1.0];
        yield 'infinite request timeout' => [INF, 1.0];
        yield 'zero connect timeout' => [1.0, 0.0];
        yield 'negative connect timeout' => [1.0, -1.0];
        yield 'infinite connect timeout' => [1.0, INF];
    }

    public function testCustomLocalEndpointAndMetadataAreAccepted(): void
    {
        $transport = TransportConfiguration::directHttp(2.5, 0.5);
        $configuration = new ClientConfiguration(
            'http://localhost:8080/calipso/',
            'secret-key',
            $transport,
            FailurePolicy::IGNORE,
            'development',
            'checkout'
        );

        self::assertSame('http://localhost:8080/calipso', $configuration->endpoint());
        self::assertSame(2.5, $configuration->transport()->requestTimeout());
        self::assertSame(0.5, $configuration->transport()->connectTimeout());
        self::assertSame('development', $configuration->environment());
        self::assertSame('checkout', $configuration->service());
    }

    public function testCredentialIsRedactedFromDiagnostics(): void
    {
        $apiKey = 'do-not-leak-this-key';
        $configuration = new ClientConfiguration('https://api.example.com', $apiKey);

        self::assertStringNotContainsString($apiKey, (string) $configuration);
        self::assertStringContainsString('[REDACTED]', (string) $configuration);
        self::assertStringNotContainsString($apiKey, print_r($configuration, true));
        self::assertStringNotContainsString($apiKey, json_encode($configuration, JSON_THROW_ON_ERROR));
    }

    public function testCredentialIsNotIncludedInValidationException(): void
    {
        $apiKey = 'do-not-leak-this-key';

        try {
            new ClientConfiguration('not a URL', $apiKey);
            self::fail('Expected invalid configuration to be rejected.');
        } catch (InvalidConfiguration $exception) {
            self::assertStringNotContainsString($apiKey, $exception->getMessage());
        }
    }
}
