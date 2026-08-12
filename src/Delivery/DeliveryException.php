<?php

declare(strict_types=1);

namespace Calipso\Sdk\Delivery;

use RuntimeException;

final class DeliveryException extends RuntimeException
{
    /** @var DeliveryResult */
    private $result;

    public function __construct(DeliveryResult $result)
    {
        parent::__construct(sprintf(
            'Calipso delivery failed with outcome "%s" and error code "%s".',
            $result->status(),
            $result->errorCode() ?? 'none'
        ));

        $this->result = $result;
    }

    public function result(): DeliveryResult
    {
        return $this->result;
    }
}
