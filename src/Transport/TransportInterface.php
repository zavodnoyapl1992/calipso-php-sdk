<?php

declare(strict_types=1);

namespace Calipso\Sdk\Transport;

use Calipso\Sdk\Delivery\DeliveryResult;
use Calipso\Sdk\Event\Event;

interface TransportInterface
{
    public function send(Event $event): DeliveryResult;
}
