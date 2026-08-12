<?php

declare(strict_types=1);

namespace Calipso\Sdk\Transport;

use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Configuration\FailurePolicy;
use Calipso\Sdk\Delivery\DeliveryException;
use Calipso\Sdk\Delivery\DeliveryResult;
use Calipso\Sdk\Delivery\SleeperInterface;
use Calipso\Sdk\Delivery\SystemSleeper;
use Calipso\Sdk\Diagnostic\DeliveryDiagnostic;
use Calipso\Sdk\Diagnostic\DiagnosticHandlerInterface;
use Calipso\Sdk\Event\Event;

final class ResilientTransport implements BatchTransportInterface
{
    /** @var TransportInterface */
    private $transport;

    /** @var ClientConfiguration */
    private $configuration;

    /** @var DiagnosticHandlerInterface|null */
    private $diagnostics;

    /** @var SleeperInterface */
    private $sleeper;

    public function __construct(
        TransportInterface $transport,
        ClientConfiguration $configuration,
        ?DiagnosticHandlerInterface $diagnostics = null,
        ?SleeperInterface $sleeper = null
    ) {
        $this->transport = $transport;
        $this->configuration = $configuration;
        $this->diagnostics = $diagnostics;
        $this->sleeper = $sleeper ?? new SystemSleeper();
    }

    public function send(Event $event): DeliveryResult
    {
        $transportConfiguration = $this->configuration->transport();
        $maxAttempts = $transportConfiguration->maxAttempts();

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            $result = $this->transport->send($event);
            $retryDelay = null;

            if ($result->status() === DeliveryResult::UNAVAILABLE && $attempt < $maxAttempts) {
                $retryDelay = $this->retryDelayMilliseconds($result, $attempt);
            }

            $this->report($result, $attempt, $maxAttempts, $retryDelay);

            if ($retryDelay !== null) {
                $this->sleeper->sleep($retryDelay);

                continue;
            }

            if (!$result->isSuccess() && FailurePolicy::shouldThrow($this->configuration->failurePolicy())) {
                throw new DeliveryException($result);
            }

            return $result;
        }

        throw new \LogicException('Delivery retry loop completed without a result.');
    }

    /**
     * @param list<Event> $events
     *
     * @return list<DeliveryResult>
     */
    public function sendBatch(array $events): array
    {
        if (!$this->transport instanceof BatchTransportInterface) {
            throw new \Calipso\Sdk\Exception\InvalidBatch('Configured transport does not support batch delivery.');
        }

        if ($events === []) {
            throw new \Calipso\Sdk\Exception\InvalidBatch('A batch must contain at least one event.');
        }

        $configuration = $this->configuration->transport();
        $maxAttempts = $configuration->maxAttempts();
        $pendingEvents = $events;
        $pendingIndexes = array_keys($pendingEvents);
        $final = [];

        for ($attempt = 1; $attempt <= $maxAttempts && $pendingEvents !== []; ++$attempt) {
            $attemptResults = $this->transport->sendBatch($pendingEvents);
            if (count($attemptResults) !== count($pendingEvents)) {
                throw new \LogicException('Batch transport returned an unexpected result count.');
            }

            $nextEvents = [];
            $nextIndexes = [];
            $batchDelay = 0;
            foreach ($attemptResults as $position => $result) {
                $originalIndex = $pendingIndexes[$position];
                $retryDelay = null;
                if ($result->status() === DeliveryResult::UNAVAILABLE && $attempt < $maxAttempts) {
                    $retryDelay = $this->retryDelayMilliseconds($result, $attempt);
                    $batchDelay = max($batchDelay, $retryDelay);
                    $nextEvents[] = $pendingEvents[$position];
                    $nextIndexes[] = $originalIndex;
                } else {
                    $final[$originalIndex] = $result;
                }

                $this->report($result, $attempt, $maxAttempts, $retryDelay);
            }

            if ($nextEvents !== []) {
                $this->sleeper->sleep($batchDelay);
            }

            $pendingEvents = $nextEvents;
            $pendingIndexes = $nextIndexes;
        }

        ksort($final);
        $results = array_values($final);
        foreach ($results as $result) {
            if (!$result->isSuccess() && FailurePolicy::shouldThrow($this->configuration->failurePolicy())) {
                throw new DeliveryException($result);
            }
        }

        return $results;
    }

    private function retryDelayMilliseconds(DeliveryResult $result, int $attempt): int
    {
        if ($result->retryAfterSeconds() !== null) {
            return min($result->retryAfterSeconds() * 1000, 60000);
        }

        $configuration = $this->configuration->transport();
        $exponential = min(
            $configuration->initialBackoffMilliseconds() * (2 ** ($attempt - 1)),
            $configuration->maxBackoffMilliseconds()
        );
        $minimum = (int) floor($exponential / 2);

        return random_int($minimum, $exponential);
    }

    private function report(
        DeliveryResult $result,
        int $attempt,
        int $maxAttempts,
        ?int $retryDelayMilliseconds
    ): void {
        if ($this->diagnostics === null) {
            return;
        }

        $this->diagnostics->report(new DeliveryDiagnostic(
            $result->status(),
            $result->errorCode(),
            $attempt,
            $maxAttempts,
            $retryDelayMilliseconds
        ));
    }
}
