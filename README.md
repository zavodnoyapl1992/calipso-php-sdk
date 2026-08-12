# Calipso PHP SDK

The official PHP SDK for the Calipso event API. The API implementation is under development.

## Requirements

- PHP 7.4 or newer
- Composer 2

## Installation

The package is not published to Packagist yet. Installation instructions will be added with the first release.

## Development

Install dependencies and run every quality check:

```shell
composer install
composer check
```

Run the test suite on its own with `composer test`.

## Configuration

Construct a client with an endpoint and project API key. Direct HTTP transport, a 10-second request timeout, a 3-second connect timeout, and exceptions ignore are the defaults.

```php
use Calipso\Sdk\Client;
use Calipso\Sdk\Configuration\ClientConfiguration;

$configuration = new ClientConfiguration(
    'https://api.example.com',
    getenv('CALIPSO_API_KEY')
);

// Inject a TransportInterface implementation appropriate for the application.
$client = new Client($configuration, $transport);
```

Transport settings and optional application metadata can be customized without coupling the SDK to a framework:

```php
use Calipso\Sdk\Configuration\FailurePolicy;
use Calipso\Sdk\Configuration\TransportConfiguration;

$configuration = new ClientConfiguration(
    'http://localhost:8080',
    getenv('CALIPSO_API_KEY'),
    TransportConfiguration::directHttp(5.0, 1.0),
    FailurePolicy::IGNORE,
    'development',
    'checkout-service'
);
```

API keys are excluded from configuration diagnostics. Applications should still avoid logging the value returned by `ClientConfiguration::apiKey()`.

## Events

Events are transport-independent immutable logical records. Their generated identity and UTC occurrence timestamp remain stable when the same instance is serialized for a retry.

```php
use Calipso\Sdk\Event\EntityReference;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Event\EventEnvelopeSerializer;

$event = new Event(
    'payment.approved',
    [new EntityReference('payment', 'pay-42')],
    ['amount' => '125.50', 'currency' => 'EUR'],
    'checkout-9001'
);

$envelopeJson = (new EventEnvelopeSerializer())->serialize($event);
```

The envelope contains only protocol fields. Project credentials and SDK metadata are transport concerns and are never added to event payloads.

The same event model is available through the fluent client API:

```php
$result = $client->event('payment.created')
    ->entity('payment', $paymentId)
    ->entity('customer', $customerId)
    ->correlation($correlationId)
    ->payload($payload)
    ->send();
```

`Client` depends only on `TransportInterface`; application code does not construct HTTP requests or select between direct and Agent delivery.
`send()` returns a `DeliveryResult` with an `accepted`, `duplicate`, `rejected`, `unavailable`, or `queue_full` outcome. Failure-policy and diagnostic handling can therefore evolve without changing the transport or fluent API contracts.

## Direct HTTP transport

`DirectHttpBatchTransport` sends every event through `POST /api/v1/events/batch`, including a single event. The included `CurlHttpClient` uses the configured connect/request timeouts. The small framework-neutral `HttpClientInterface` also allows applications to adapt their preferred PSR-compatible HTTP client without coupling the SDK core to a framework.

```php
use Calipso\Sdk\Transport\DirectHttpBatchTransport;
use Calipso\Sdk\Http\CurlHttpClient;

$transport = new DirectHttpBatchTransport($configuration, new CurlHttpClient());
$client = new Client($configuration, $transport);
```

Accepted, duplicate, rejected, rate-limited, server-error, and network outcomes are mapped to `DeliveryResult`. A `429` result exposes validated `retryAfterSeconds()` for bounded retry handling.

## License

This project is licensed under the [MIT License](LICENSE).
