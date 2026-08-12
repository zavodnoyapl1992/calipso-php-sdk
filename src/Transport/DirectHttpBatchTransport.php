<?php

declare(strict_types=1);

namespace Calipso\Sdk\Transport;

use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Delivery\DeliveryResult;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Event\EventEnvelopeSerializer;
use Calipso\Sdk\Http\HttpClientInterface;
use Calipso\Sdk\Http\HttpRequest;
use Calipso\Sdk\Http\HttpResponse;
use Calipso\Sdk\Http\HttpTransportException;
use Throwable;

final class DirectHttpBatchTransport implements BatchTransportInterface
{
    private const BATCH_PATH = '/api/v1/events/batch';
    private const MAX_BATCH_EVENTS = 500;
    private const MAX_UNCOMPRESSED_BYTES = 16777216;

    /** @var ClientConfiguration */
    private $configuration;

    /** @var HttpClientInterface */
    private $httpClient;

    /** @var EventEnvelopeSerializer */
    private $serializer;

    public function __construct(
        ClientConfiguration $configuration,
        HttpClientInterface $httpClient,
        ?EventEnvelopeSerializer $serializer = null
    ) {
        $this->configuration = $configuration;
        $this->httpClient = $httpClient;
        $this->serializer = $serializer ?? new EventEnvelopeSerializer();
    }

    public function send(Event $event): DeliveryResult
    {
        return $this->sendBatch([$event])[0];
    }

    /**
     * @param array<mixed> $events
     *
     * @return list<DeliveryResult>
     */
    public function sendBatch(array $events): array
    {
        if ($events === []) {
            throw new \Calipso\Sdk\Exception\InvalidBatch('A batch must contain at least one event.');
        }

        $validated = [];
        foreach ($events as $event) {
            if (!$event instanceof Event) {
                throw new \Calipso\Sdk\Exception\InvalidBatch('Every batch item must be an Event.');
            }

            $validated[] = $event;
        }

        $allResults = [];
        foreach (array_chunk($validated, self::MAX_BATCH_EVENTS) as $chunk) {
            foreach ($this->sendChunk($chunk) as $result) {
                $allResults[] = $result;
            }
        }

        return $allResults;
    }

    /**
     * @param list<Event> $events
     *
     * @return list<DeliveryResult>
     */
    private function sendChunk(array $events): array
    {
        $request = $this->createRequest($events);

        try {
            $response = $this->httpClient->send($request);
        } catch (HttpTransportException $exception) {
            return array_map(static function (Event $event): DeliveryResult {
                return DeliveryResult::unavailable($event->eventId(), 'transport_error');
            }, $events);
        }

        return $this->mapResponse($events, $response);
    }

    /** @param list<Event> $events */
    private function createRequest(array $events): HttpRequest
    {
        $body = json_encode(
            ['events' => array_map(function (Event $event): array {
                // serialize() enforces the 256 KiB per-event limit.
                $this->serializer->serialize($event);

                return $this->serializer->toArray($event);
            }, $events)],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
            512
        );
        if (strlen($body) > self::MAX_UNCOMPRESSED_BYTES) {
            throw new \Calipso\Sdk\Exception\InvalidBatch('Batch request exceeds the 16 MiB uncompressed limit.');
        }
        $transport = $this->configuration->transport();

        return new HttpRequest(
            'POST',
            $this->configuration->endpoint() . self::BATCH_PATH,
            [
                'Authorization' => 'Bearer ' . $this->configuration->apiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Calipso-Protocol-Version' => '1',
                'X-Calipso-SDK' => 'php/0.1.0',
            ],
            $body,
            $transport->connectTimeout(),
            $transport->requestTimeout()
        );
    }

    /**
     * @param list<Event> $events
     *
     * @return list<DeliveryResult>
     */
    private function mapResponse(array $events, HttpResponse $response): array
    {
        $statusCode = $response->statusCode();

        if ($statusCode === 202 || $statusCode === 207) {
            return $this->mapBatchResponse($events, $response->body());
        }

        $errorCode = self::extractErrorCode($response->body());

        if ($statusCode === 401) {
            return self::sameResult($events, DeliveryResult::REJECTED, $errorCode ?? 'invalid_credential');
        }

        if ($statusCode === 403) {
            return self::sameResult($events, DeliveryResult::REJECTED, $errorCode ?? 'access_denied');
        }

        if ($statusCode === 409) {
            return self::sameResult($events, DeliveryResult::REJECTED, $errorCode ?? 'event_id_conflict');
        }

        if ($statusCode === 413) {
            return self::sameResult($events, DeliveryResult::REJECTED, $errorCode ?? 'payload_too_large');
        }

        if ($statusCode === 429) {
            return self::sameResult(
                $events,
                DeliveryResult::UNAVAILABLE,
                $errorCode ?? 'rate_limit_exceeded',
                self::parseRetryAfter($response->header('Retry-After'))
            );
        }

        if ($statusCode >= 500) {
            return self::sameResult($events, DeliveryResult::UNAVAILABLE, $errorCode ?? 'server_error');
        }

        return self::sameResult($events, DeliveryResult::REJECTED, $errorCode ?? 'unexpected_http_status');
    }

    /**
     * @param list<Event> $events
     *
     * @return list<DeliveryResult>
     */
    private function mapBatchResponse(array $events, string $body): array
    {
        $decoded = self::decodeObject($body);
        if ($decoded === null || !isset($decoded['results']) || !is_array($decoded['results'])) {
            return self::sameResult($events, DeliveryResult::UNAVAILABLE, 'invalid_response');
        }

        $mapped = [];
        foreach ($decoded['results'] as $result) {
            if (!is_array($result) || !is_int($result['index'] ?? null) || !is_string($result['status'] ?? null)) {
                continue;
            }

            $index = $result['index'];
            if (!isset($events[$index]) || isset($mapped[$index])) {
                continue;
            }

            $event = $events[$index];
            if (
                array_key_exists('eventId', $result)
                && $result['eventId'] !== null
                && $result['eventId'] !== $event->eventId()
            ) {
                $mapped[$index] = DeliveryResult::unavailable($event->eventId(), 'mismatched_event_id');
                continue;
            }

            if ($result['status'] === 'accepted') {
                $mapped[$index] = DeliveryResult::accepted($event->eventId());
                continue;
            }

            if ($result['status'] === 'duplicate') {
                $mapped[$index] = DeliveryResult::duplicate($event->eventId());
                continue;
            }

            if ($result['status'] === 'rejected') {
                $error = is_string($result['error'] ?? null) && trim($result['error']) !== ''
                    ? $result['error']
                    : 'invalid_event';

                $mapped[$index] = DeliveryResult::rejected($event->eventId(), $error);
                continue;
            }

            $mapped[$index] = DeliveryResult::unavailable($event->eventId(), 'unknown_result_status');
        }

        $results = [];
        foreach ($events as $index => $event) {
            $results[] = $mapped[$index] ?? DeliveryResult::unavailable($event->eventId(), 'missing_result');
        }

        return $results;
    }

    /**
     * @param list<Event> $events
     *
     * @return list<DeliveryResult>
     */
    private static function sameResult(
        array $events,
        string $status,
        string $errorCode,
        ?int $retryAfterSeconds = null
    ): array {
        return array_map(static function (Event $event) use ($status, $errorCode, $retryAfterSeconds): DeliveryResult {
            return $status === DeliveryResult::REJECTED
                ? DeliveryResult::rejected($event->eventId(), $errorCode)
                : DeliveryResult::unavailable($event->eventId(), $errorCode, $retryAfterSeconds);
        }, $events);
    }

    private static function extractErrorCode(string $body): ?string
    {
        $decoded = self::decodeObject($body);
        if (
            $decoded === null
            || !isset($decoded['error'])
            || !is_array($decoded['error'])
            || !is_string($decoded['error']['code'] ?? null)
            || trim($decoded['error']['code']) === ''
        ) {
            return null;
        }

        return $decoded['error']['code'];
    }

    /** @return array<array-key, mixed>|null */
    private static function decodeObject(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || preg_match('/^[1-9][0-9]*$/D', trim($header)) !== 1) {
            return null;
        }

        $seconds = (int) trim($header);

        return $seconds > 0 ? $seconds : null;
    }
}
