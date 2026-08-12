<?php

declare(strict_types=1);

namespace Calipso\Sdk\Transport;

use Calipso\Sdk\Event\Event;

interface BatchTransportInterface extends TransportInterface
{
    /**
     * @param list<Event> $events
     *
     * @return list<\Calipso\Sdk\Delivery\DeliveryResult>
     */
    public function sendBatch(array $events): array;
}
