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

$client = new Client(new ClientConfiguration(
    'https://api.example.com',
    getenv('CALIPSO_API_KEY')
));
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

## License

This project is licensed under the [MIT License](LICENSE).
