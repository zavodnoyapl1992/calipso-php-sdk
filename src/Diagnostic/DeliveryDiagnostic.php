<?php

declare(strict_types=1);

namespace Calipso\Sdk\Diagnostic;

final class DeliveryDiagnostic
{
    /** @var string */
    private $outcome;

    /** @var string|null */
    private $errorCode;

    /** @var int */
    private $attempt;

    /** @var int */
    private $maxAttempts;

    /** @var int|null */
    private $retryDelayMilliseconds;

    public function __construct(
        string $outcome,
        ?string $errorCode,
        int $attempt,
        int $maxAttempts,
        ?int $retryDelayMilliseconds
    ) {
        $this->outcome = $outcome;
        $this->errorCode = $errorCode;
        $this->attempt = $attempt;
        $this->maxAttempts = $maxAttempts;
        $this->retryDelayMilliseconds = $retryDelayMilliseconds;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function retryDelayMilliseconds(): ?int
    {
        return $this->retryDelayMilliseconds;
    }
}
