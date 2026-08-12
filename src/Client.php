<?php

declare(strict_types=1);

namespace Calipso\Sdk;

use Calipso\Sdk\Configuration\ClientConfiguration;
use Calipso\Sdk\Event\EventBuilder;
use Calipso\Sdk\Exception\InvalidConfiguration;
use Calipso\Sdk\Transport\TransportInterface;

final class Client
{
    /** @var ClientConfiguration */
    private $configuration;

    /** @var TransportInterface|null */
    private $transport;

    public function __construct(ClientConfiguration $configuration, ?TransportInterface $transport = null)
    {
        $this->configuration = $configuration;
        $this->transport = $transport;
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
}
