<?php

declare(strict_types=1);

namespace Calipso\Sdk\Delivery;

interface SleeperInterface
{
    public function sleep(int $milliseconds): void;
}
