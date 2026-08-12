<?php

declare(strict_types=1);

namespace Calipso\Sdk\Diagnostic;

interface DiagnosticHandlerInterface
{
    public function report(DeliveryDiagnostic $diagnostic): void;
}
