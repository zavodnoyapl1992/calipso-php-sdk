<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests\Transport;

use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Configuration\FailurePolicy;
use Calipso\Sdk\Configuration\TransportConfiguration;
use Calipso\Sdk\Delivery\DeliveryException;
use Calipso\Sdk\Delivery\DeliveryResult;
use Calipso\Sdk\Delivery\SleeperInterface;
use Calipso\Sdk\Diagnostic\DeliveryDiagnostic;
use Calipso\Sdk\Diagnostic\DiagnosticHandlerInterface;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Transport\ResilientTransport;
use Calipso\Sdk\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

final class ResilientTransportTest extends TestCase
{
    public function testTemporaryFailureDoesNotBreakDefaultBusinessFlow(): void
    {
        $event = $this->event();
        $transport = new SequenceTransport([
            DeliveryResult::unavailable($event->eventId(), 'timeout'),
        ]);

        $result = $this->resilient($transport, FailurePolicy::IGNORE, 1)->send($event);

        self::assertSame(DeliveryResult::UNAVAILABLE, $result->status());
        self::assertSame('timeout', $result->errorCode());
    }

    public function testFailPolicySurfacesSafeDeliveryException(): void
    {
        $secret = 'credential-must-not-leak';
        $payload = 'payload-must-not-leak';
        $event = new Event('system.failed', [], ['private' => $payload], null, 'event-42');
        $transport = new SequenceTransport([
            DeliveryResult::rejected($event->eventId(), 'invalid_credential'),
        ]);
        $configuration = $this->configuration(FailurePolicy::FAIL, 1, $secret);

        try {
            (new ResilientTransport($transport, $configuration))->send($event);
            self::fail('Expected strict delivery failure.');
        } catch (DeliveryException $exception) {
            self::assertSame(DeliveryResult::REJECTED, $exception->result()->status());
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString($payload, $exception->getMessage());
        }
    }

    public function testTransientFailuresRetryWithinBoundUsingSameEvent(): void
    {
        $event = $this->event();
        $transport = new SequenceTransport([
            DeliveryResult::unavailable($event->eventId(), 'transport_error'),
            DeliveryResult::unavailable($event->eventId(), 'server_error'),
            DeliveryResult::accepted($event->eventId()),
        ]);
        $sleeper = new RecordingSleeper();

        $result = $this->resilient($transport, FailurePolicy::IGNORE, 3, $sleeper)->send($event);

        self::assertSame(DeliveryResult::ACCEPTED, $result->status());
        self::assertSame([$event, $event, $event], $transport->events());
        self::assertCount(2, $sleeper->delays());
        self::assertGreaterThanOrEqual(50, $sleeper->delays()[0]);
        self::assertLessThanOrEqual(100, $sleeper->delays()[0]);
        self::assertGreaterThanOrEqual(100, $sleeper->delays()[1]);
        self::assertLessThanOrEqual(200, $sleeper->delays()[1]);
    }

    public function testPermanentRejectionIsNotRetried(): void
    {
        $event = $this->event();
        $transport = new SequenceTransport([
            DeliveryResult::rejected($event->eventId(), 'invalid_event'),
            DeliveryResult::accepted($event->eventId()),
        ]);
        $sleeper = new RecordingSleeper();

        $result = $this->resilient($transport, FailurePolicy::IGNORE, 3, $sleeper)->send($event);

        self::assertSame(DeliveryResult::REJECTED, $result->status());
        self::assertCount(1, $transport->events());
        self::assertSame([], $sleeper->delays());
    }

    public function testRetryAfterControlsDelay(): void
    {
        $event = $this->event();
        $transport = new SequenceTransport([
            DeliveryResult::unavailable($event->eventId(), 'rate_limit_exceeded', 2),
            DeliveryResult::accepted($event->eventId()),
        ]);
        $sleeper = new RecordingSleeper();

        $this->resilient($transport, FailurePolicy::IGNORE, 2, $sleeper)->send($event);

        self::assertSame([2000], $sleeper->delays());
    }

    public function testDiagnosticsContainOnlySafeDeliveryMetadata(): void
    {
        $credential = 'credential-must-not-leak';
        $payload = 'payload-must-not-leak';
        $entityId = 'entity-must-not-leak';
        $event = new Event(
            'system.failed',
            [new \Calipso\Sdk\Event\EntityReference('account', $entityId)],
            ['private' => $payload],
            null,
            'event-must-not-leak'
        );
        $transport = new SequenceTransport([
            DeliveryResult::unavailable($event->eventId(), 'timeout'),
        ]);
        $diagnostics = new RecordingDiagnostics();
        $configuration = $this->configuration(FailurePolicy::IGNORE, 1, $credential);

        (new ResilientTransport($transport, $configuration, $diagnostics))->send($event);

        $diagnostic = $diagnostics->items()[0];
        $serialized = serialize($diagnostic);
        self::assertSame(DeliveryResult::UNAVAILABLE, $diagnostic->outcome());
        self::assertSame('timeout', $diagnostic->errorCode());
        self::assertSame(1, $diagnostic->attempt());
        self::assertSame(1, $diagnostic->maxAttempts());
        self::assertNull($diagnostic->retryDelayMilliseconds());
        self::assertStringNotContainsString($credential, $serialized);
        self::assertStringNotContainsString($payload, $serialized);
        self::assertStringNotContainsString($entityId, $serialized);
        self::assertStringNotContainsString($event->eventId(), $serialized);
    }

    public function testNoHiddenRetryAfterMaximumAttempt(): void
    {
        $event = $this->event();
        $transport = new SequenceTransport([
            DeliveryResult::unavailable($event->eventId(), 'server_error'),
            DeliveryResult::unavailable($event->eventId(), 'server_error'),
        ]);
        $sleeper = new RecordingSleeper();

        $result = $this->resilient($transport, FailurePolicy::IGNORE, 2, $sleeper)->send($event);

        self::assertSame(DeliveryResult::UNAVAILABLE, $result->status());
        self::assertCount(2, $transport->events());
        self::assertCount(1, $sleeper->delays());
    }

    private function resilient(
        TransportInterface $transport,
        string $policy,
        int $maxAttempts,
        ?SleeperInterface $sleeper = null
    ): ResilientTransport {
        return new ResilientTransport(
            $transport,
            $this->configuration($policy, $maxAttempts),
            null,
            $sleeper
        );
    }

    private function configuration(
        string $policy,
        int $maxAttempts,
        string $credential = 'project-credential'
    ): ClientConfiguration {
        return new ClientConfiguration(
            'https://calipso.example',
            $credential,
            TransportConfiguration::directHttp(10.0, 3.0, $maxAttempts, 100, 1000),
            $policy
        );
    }

    private function event(): Event
    {
        return new Event('system.started', [], [], null, 'event-42');
    }
}

final class SequenceTransport implements TransportInterface
{
    /** @var list<DeliveryResult> */
    private $results;

    /** @var list<Event> */
    private $events = [];

    /** @param list<DeliveryResult> $results */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function send(Event $event): DeliveryResult
    {
        $this->events[] = $event;
        $result = array_shift($this->results);
        if (!$result instanceof DeliveryResult) {
            throw new \LogicException('No delivery result configured.');
        }

        return $result;
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->events;
    }
}

final class RecordingSleeper implements SleeperInterface
{
    /** @var list<int> */
    private $delays = [];

    public function sleep(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }

    /** @return list<int> */
    public function delays(): array
    {
        return $this->delays;
    }
}

final class RecordingDiagnostics implements DiagnosticHandlerInterface
{
    /** @var list<DeliveryDiagnostic> */
    private $items = [];

    public function report(DeliveryDiagnostic $diagnostic): void
    {
        $this->items[] = $diagnostic;
    }

    /** @return list<DeliveryDiagnostic> */
    public function items(): array
    {
        return $this->items;
    }
}
