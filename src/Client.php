<?php

declare(strict_types=1);

namespace Calipso\Sdk;

use Calipso\Sdk\Configuration\ClientConfiguration;

final class Client
{
    /** @var ClientConfiguration */
    private $configuration;

    public function __construct(ClientConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function configuration(): ClientConfiguration
    {
        return $this->configuration;
    }
}
