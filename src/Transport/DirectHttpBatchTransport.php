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

final class DirectHttpBatchTransport implements TransportInterface
{
    private const BATCH_PATH = '/api/v1/events/batch';

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
        $request = $this->createRequest($event);

        try {
            $response = $this->httpClient->send($request);
        } catch (HttpTransportException $exception) {
            return DeliveryResult::unavailable($event->eventId(), 'transport_error');
        }

        return $this->mapResponse($event, $response);
    }

    private function createRequest(Event $event): HttpRequest
    {
        $body = json_encode(
            ['events' => [$this->serializer->toArray($event)]],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
            512
        );
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

    private function mapResponse(Event $event, HttpResponse $response): DeliveryResult
    {
        $statusCode = $response->statusCode();

        if ($statusCode === 202 || $statusCode === 207) {
            return $this->mapBatchResponse($event, $response->body());
        }

        $errorCode = self::extractErrorCode($response->body());

        if ($statusCode === 401) {
            return DeliveryResult::rejected($event->eventId(), $errorCode ?? 'invalid_credential');
        }

        if ($statusCode === 403) {
            return DeliveryResult::rejected($event->eventId(), $errorCode ?? 'access_denied');
        }

        if ($statusCode === 409) {
            return DeliveryResult::rejected($event->eventId(), $errorCode ?? 'event_id_conflict');
        }

        if ($statusCode === 413) {
            return DeliveryResult::rejected($event->eventId(), $errorCode ?? 'payload_too_large');
        }

        if ($statusCode === 429) {
            return DeliveryResult::unavailable(
                $event->eventId(),
                $errorCode ?? 'rate_limit_exceeded',
                self::parseRetryAfter($response->header('Retry-After'))
            );
        }

        if ($statusCode >= 500) {
            return DeliveryResult::unavailable($event->eventId(), $errorCode ?? 'server_error');
        }

        return DeliveryResult::rejected($event->eventId(), $errorCode ?? 'unexpected_http_status');
    }

    private function mapBatchResponse(Event $event, string $body): DeliveryResult
    {
        $decoded = self::decodeObject($body);
        if ($decoded === null || !isset($decoded['results']) || !is_array($decoded['results'])) {
            return DeliveryResult::unavailable($event->eventId(), 'invalid_response');
        }

        foreach ($decoded['results'] as $result) {
            if (!is_array($result) || ($result['index'] ?? null) !== 0 || !is_string($result['status'] ?? null)) {
                continue;
            }

            if ($result['status'] === 'accepted') {
                return DeliveryResult::accepted($event->eventId());
            }

            if ($result['status'] === 'duplicate') {
                return DeliveryResult::duplicate($event->eventId());
            }

            if ($result['status'] === 'rejected') {
                $error = is_string($result['error'] ?? null) && trim($result['error']) !== ''
                    ? $result['error']
                    : 'invalid_event';

                return DeliveryResult::rejected($event->eventId(), $error);
            }

            return DeliveryResult::unavailable($event->eventId(), 'unknown_result_status');
        }

        return DeliveryResult::unavailable($event->eventId(), 'missing_result');
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
