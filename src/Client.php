<?php

declare(strict_types=1);

namespace Calipso\Sdk;

use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Event\EventBuilder;
use Calipso\Sdk\Diagnostic\DiagnosticHandlerInterface;
use Calipso\Sdk\Delivery\SleeperInterface;
use Calipso\Sdk\Exception\InvalidConfiguration;
use Calipso\Sdk\Transport\TransportInterface;
use Calipso\Sdk\Transport\ResilientTransport;
use Calipso\Sdk\Event\Event;
use Calipso\Sdk\Delivery\DeliveryResult;

final class Client
{
    /** @var ClientConfiguration */
    private $configuration;

    /** @var TransportInterface|null */
    private $transport;

    public function __construct(
        ClientConfiguration $configuration,
        ?TransportInterface $transport = null,
        ?DiagnosticHandlerInterface $diagnostics = null,
        ?SleeperInterface $sleeper = null
    ) {
        $this->configuration = $configuration;
        $this->transport = $transport === null
            ? null
            : new ResilientTransport($transport, $configuration, $diagnostics, $sleeper);
    }

    public function configuration(): ClientConfiguration
    {
        return $this->configuration;
    }

    public function event(string $type): EventBuilder
    {
        if ($this->transport === null) {
            throw new InvalidConfiguration('A transport is required to send events.');
        }

        return new EventBuilder($this->transport, $type);
    }

    /**
     * @param list<Event> $events
     *
     * @return list<DeliveryResult>
     */
    public function sendBatch(array $events): array
    {
        if (!$this->transport instanceof \Calipso\Sdk\Transport\BatchTransportInterface) {
            throw new InvalidConfiguration('Configured transport does not support batch delivery.');
        }

        return $this->transport->sendBatch($events);
    }
}
