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

Construct a client with an endpoint and project API key. Direct HTTP transport, a 10-second request timeout, a 3-second connect timeout, and exceptions on failure are the defaults.

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

## License

This project is licensed under the [MIT License](LICENSE).
