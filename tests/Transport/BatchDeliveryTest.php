<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests\Transport;

use Calipso\Sdk\Client;
use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Configuration\TransportConfiguration;
use Calipso\Sdk\Delivery\DeliveryResult;
use Calipso\Sdk\Delivery\SleeperInterface;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Exception\InvalidEvent;
use Calipso\Sdk\Http\HttpClientInterface;
use Calipso\Sdk\Http\HttpRequest;
use Calipso\Sdk\Http\HttpResponse;
use Calipso\Sdk\Transport\BatchTransportInterface;
use Calipso\Sdk\Transport\DirectHttpBatchTransport;
use PHPUnit\Framework\TestCase;

final class BatchDeliveryTest extends TestCase
{
    public function testThreeEventsUseOneRequestAndMixedResultsPreserveOrder(): void
    {
        $events = [$this->event('event-1'), $this->event('event-2'), $this->event('event-3')];
        $http = new BatchHttpClient([
            new HttpResponse(207, [], json_encode(['results' => [
                ['index' => 0, 'eventId' => 'event-1', 'status' => 'accepted'],
                ['index' => 1, 'eventId' => 'event-2', 'status' => 'duplicate'],
                ['index' => 2, 'eventId' => 'event-3', 'status' => 'rejected', 'error' => 'invalid_event'],
            ]], JSON_THROW_ON_ERROR)),
        ]);

        $results = $this->direct($http)->sendBatch($events);

        self::assertCount(1, $http->requests());
        self::assertSame(['event-1', 'event-2', 'event-3'], array_map(static function (DeliveryResult $result): string {
            return $result->eventId();
        }, $results));
        self::assertSame([
            DeliveryResult::ACCEPTED,
            DeliveryResult::DUPLICATE,
            DeliveryResult::REJECTED,
        ], array_map(static function (DeliveryResult $result): string {
            return $result->status();
        }, $results));
        self::assertSame(
            ['event-1', 'event-2', 'event-3'],
            array_column(self::decodeEvents($http->requests()[0]), 'eventId')
        );
    }

    public function testMoreThanFiveHundredEventsAreChunkedDeterministically(): void
    {
        $events = [];
        for ($index = 0; $index < 501; ++$index) {
            $events[] = $this->event('event-' . $index);
        }
        $http = new BatchHttpClient([
            new HttpResponse(202, [], self::acceptedBody($events, 0, 500)),
            new HttpResponse(202, [], self::acceptedBody($events, 500, 1)),
        ]);

        $results = $this->direct($http)->sendBatch($events);

        self::assertCount(501, $results);
        self::assertCount(2, $http->requests());
        self::assertCount(500, self::decodeEvents($http->requests()[0]));
        self::assertCount(1, self::decodeEvents($http->requests()[1]));
    }

    public function testPartialRetrySendsOnlyUnavailableItemsWithStableIdentity(): void
    {
        $events = [$this->event('event-1'), $this->event('event-2'), $this->event('event-3')];
        $transport = new SequenceBatchTransport([
            [
                DeliveryResult::accepted('event-1'),
                DeliveryResult::unavailable('event-2', 'server_error'),
                DeliveryResult::rejected('event-3', 'invalid_event'),
            ],
            [DeliveryResult::duplicate('event-2')],
        ]);
        $configuration = new ClientConfiguration(
            'https://calipso.example',
            'secret',
            TransportConfiguration::directHttp(2.0, 0.5, 2, 0, 0)
        );
        $client = new Client($configuration, $transport, null, new NoopBatchSleeper());

        $results = $client->sendBatch($events);

        self::assertSame([
            DeliveryResult::ACCEPTED,
            DeliveryResult::DUPLICATE,
            DeliveryResult::REJECTED,
        ], array_map(static function (DeliveryResult $result): string {
            return $result->status();
        }, $results));
        self::assertSame($events, $transport->attempts()[0]);
        self::assertSame([$events[1]], $transport->attempts()[1]);
        self::assertSame('event-2', $transport->attempts()[1][0]->eventId());
    }

    public function testOversizedEventIsRejectedBeforeHttpRequest(): void
    {
        $http = new BatchHttpClient([]);
        $event = new Event('system.large', [], ['value' => str_repeat('x', 262144)], null, 'event-large');

        $this->expectException(InvalidEvent::class);

        $this->direct($http)->sendBatch([$event]);
    }

    private function direct(HttpClientInterface $http): DirectHttpBatchTransport
    {
        return new DirectHttpBatchTransport(
            new ClientConfiguration('https://calipso.example', 'secret'),
            $http
        );
    }

    private function event(string $eventId): Event
    {
        return new Event('system.processed', [], ['position' => $eventId], null, $eventId);
    }

    /** @param list<Event> $events */
    private static function acceptedBody(array $events, int $offset, int $length): string
    {
        $results = [];
        foreach (array_slice($events, $offset, $length) as $index => $event) {
            $results[] = ['index' => $index, 'eventId' => $event->eventId(), 'status' => 'accepted'];
        }

        return json_encode(['results' => $results], JSON_THROW_ON_ERROR);
    }

    /** @return list<array<array-key, mixed>> */
    private static function decodeEvents(HttpRequest $request): array
    {
        $decoded = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['events']) || !is_array($decoded['events'])) {
            throw new \LogicException('Request does not contain an events array.');
        }

        $events = [];
        foreach ($decoded['events'] as $event) {
            if (!is_array($event)) {
                throw new \LogicException('Event envelope is not an object.');
            }
            $events[] = $event;
        }

        return $events;
    }
}

final class BatchHttpClient implements HttpClientInterface
{
    /** @var list<HttpResponse> */
    private $responses;

    /** @var list<HttpRequest> */
    private $requests = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if (!$response instanceof HttpResponse) {
            throw new \LogicException('No response configured.');
        }

        return $response;
    }

    /** @return list<HttpRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}

final class SequenceBatchTransport implements BatchTransportInterface
{
    /** @var list<list<DeliveryResult>> */
    private $results;

    /** @var list<list<Event>> */
    private $attempts = [];

    /** @param list<list<DeliveryResult>> $results */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function send(Event $event): DeliveryResult
    {
        return $this->sendBatch([$event])[0];
    }

    public function sendBatch(array $events): array
    {
        $this->attempts[] = $events;
        $results = array_shift($this->results);
        if (!is_array($results)) {
            throw new \LogicException('No results configured.');
        }

        return $results;
    }

    /** @return list<list<Event>> */
    public function attempts(): array
    {
        return $this->attempts;
    }
}

final class NoopBatchSleeper implements SleeperInterface
{
    public function sleep(int $milliseconds): void {}
}
