<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests\Transport;

use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Configuration\TransportConfiguration;
use Calipso\Sdk\Delivery\DeliveryResult;
use Calipso\Sdk\Event\EntityReference;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Http\HttpClientInterface;
use Calipso\Sdk\Http\HttpRequest;
use Calipso\Sdk\Http\HttpResponse;
use Calipso\Sdk\Http\HttpTransportException;
use Calipso\Sdk\Transport\DirectHttpBatchTransport;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DirectHttpBatchTransportTest extends TestCase
{
    public function testOneEventUsesBatchEndpointAndConfiguredRequest(): void
    {
        $http = new StubHttpClient(new HttpResponse(202, [], self::batchResult('accepted')));
        $configuration = new ClientConfiguration(
            'https://calipso.example/base',
            'project-secret',
            TransportConfiguration::directHttp(4.5, 0.75)
        );
        $event = $this->event();

        $result = (new DirectHttpBatchTransport($configuration, $http))->send($event);

        self::assertSame(DeliveryResult::ACCEPTED, $result->status());
        $request = $http->lastRequest();
        self::assertSame('POST', $request->method());
        self::assertSame('https://calipso.example/base/api/v1/events/batch', $request->url());
        self::assertSame('Bearer project-secret', $request->headers()['Authorization']);
        self::assertSame('application/json', $request->headers()['Content-Type']);
        self::assertSame('1', $request->headers()['X-Calipso-Protocol-Version']);
        self::assertSame(0.75, $request->connectTimeout());
        self::assertSame(4.5, $request->requestTimeout());
        self::assertSame(
            ['events' => [[
                'eventId' => 'event-42',
                'type' => 'payment.created',
                'occurredAt' => '2026-08-12T10:00:00.000000Z',
                'entities' => [['type' => 'payment', 'id' => 'pay-42']],
                'payload' => ['amount' => '125.50'],
            ]]],
            json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /** @dataProvider batchResultProvider */
    public function testMapsPerEventBatchResult(string $serverStatus, string $expectedStatus): void
    {
        $http = new StubHttpClient(new HttpResponse(207, [], self::batchResult($serverStatus)));

        $result = $this->transport($http)->send($this->event());

        self::assertSame($expectedStatus, $result->status());
        self::assertSame('event-42', $result->eventId());
        self::assertSame($serverStatus === 'rejected' ? 'invalid_event' : null, $result->errorCode());
    }

    /** @return iterable<string, array{string, string}> */
    public function batchResultProvider(): iterable
    {
        yield 'accepted' => ['accepted', DeliveryResult::ACCEPTED];
        yield 'duplicate' => ['duplicate', DeliveryResult::DUPLICATE];
        yield 'rejected' => ['rejected', DeliveryResult::REJECTED];
    }

    /** @dataProvider permanentHttpFailureProvider */
    public function testPermanentHttpFailuresAreRejected(int $statusCode, string $body, string $errorCode): void
    {
        $http = new StubHttpClient(new HttpResponse($statusCode, [], $body));

        $result = $this->transport($http)->send($this->event());

        self::assertSame(DeliveryResult::REJECTED, $result->status());
        self::assertSame($errorCode, $result->errorCode());
    }

    /** @return iterable<string, array{int, string, string}> */
    public function permanentHttpFailureProvider(): iterable
    {
        yield 'authentication' => [401, self::error('invalid_credential'), 'invalid_credential'];
        yield 'authorization' => [403, self::error('project_suspended'), 'project_suspended'];
        yield 'conflict' => [409, self::error('event_id_conflict'), 'event_id_conflict'];
        yield 'too large' => [413, self::error('payload_too_large'), 'payload_too_large'];
    }

    public function testRateLimitIsUnavailableAndPreservesRetryAfter(): void
    {
        $http = new StubHttpClient(new HttpResponse(
            429,
            ['Retry-After' => '17'],
            self::error('rate_limit_exceeded')
        ));

        $result = $this->transport($http)->send($this->event());

        self::assertSame(DeliveryResult::UNAVAILABLE, $result->status());
        self::assertSame('rate_limit_exceeded', $result->errorCode());
        self::assertSame(17, $result->retryAfterSeconds());
    }

    public function testServerFailureIsUnavailable(): void
    {
        $http = new StubHttpClient(new HttpResponse(503, [], self::error('internal_error')));

        $result = $this->transport($http)->send($this->event());

        self::assertSame(DeliveryResult::UNAVAILABLE, $result->status());
        self::assertSame('internal_error', $result->errorCode());
    }

    public function testNetworkFailureIsUnavailableAndDoesNotLeakExceptionMessage(): void
    {
        $secret = 'project-secret';
        $payloadValue = 'private-payload-value';
        $http = new StubHttpClient(null, new HttpTransportException($secret . ' ' . $payloadValue));
        $configuration = new ClientConfiguration('https://calipso.example', $secret);
        $event = new Event('payment.created', [], ['private' => $payloadValue], null, 'event-42');

        $result = (new DirectHttpBatchTransport($configuration, $http))->send($event);
        $diagnostic = implode(' ', [
            $result->status(),
            $result->eventId(),
            (string) $result->errorCode(),
        ]);

        self::assertSame(DeliveryResult::UNAVAILABLE, $result->status());
        self::assertSame('transport_error', $result->errorCode());
        self::assertStringNotContainsString($secret, $diagnostic);
        self::assertStringNotContainsString($payloadValue, $diagnostic);
    }

    public function testProgrammerErrorFromHttpAdapterIsNotSwallowed(): void
    {
        $http = new BrokenHttpClient();

        $this->expectException(\TypeError::class);

        $this->transport($http)->send($this->event());
    }

    public function testMissingPerEventResultIsNotSilentlyAccepted(): void
    {
        $http = new StubHttpClient(new HttpResponse(202, [], '{"results":[]}'));

        $result = $this->transport($http)->send($this->event());

        self::assertSame(DeliveryResult::UNAVAILABLE, $result->status());
        self::assertSame('missing_result', $result->errorCode());
    }

    private function transport(HttpClientInterface $http): DirectHttpBatchTransport
    {
        return new DirectHttpBatchTransport(
            new ClientConfiguration('https://calipso.example', 'project-secret'),
            $http
        );
    }

    private function event(): Event
    {
        return new Event(
            'payment.created',
            [new EntityReference('payment', 'pay-42')],
            ['amount' => '125.50'],
            null,
            'event-42',
            new DateTimeImmutable('2026-08-12T10:00:00Z')
        );
    }

    private static function batchResult(string $status): string
    {
        $result = ['index' => 0, 'eventId' => 'event-42', 'status' => $status];
        if ($status === 'rejected') {
            $result['error'] = 'invalid_event';
        }

        return json_encode(['results' => [$result]], JSON_THROW_ON_ERROR);
    }

    private static function error(string $code): string
    {
        return json_encode(['error' => ['code' => $code]], JSON_THROW_ON_ERROR);
    }
}

final class StubHttpClient implements HttpClientInterface
{
    /** @var HttpResponse|null */
    private $response;

    /** @var HttpTransportException|null */
    private $exception;

    /** @var HttpRequest|null */
    private $lastRequest;

    public function __construct(?HttpResponse $response, ?HttpTransportException $exception = null)
    {
        $this->response = $response;
        $this->exception = $exception;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->lastRequest = $request;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->response === null) {
            throw new HttpTransportException('No response configured.');
        }

        return $this->response;
    }

    public function lastRequest(): HttpRequest
    {
        if ($this->lastRequest === null) {
            throw new \LogicException('No request was sent.');
        }

        return $this->lastRequest;
    }
}

final class BrokenHttpClient implements HttpClientInterface
{
    public function send(HttpRequest $request): HttpResponse
    {
        throw new \TypeError('Broken custom HTTP adapter.');
    }
}
