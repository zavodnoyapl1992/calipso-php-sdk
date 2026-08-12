<?php

declare(strict_types=1);

namespace Calipso\Sdk\Tests;

use Calipso\Sdk\Sdk;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPackageClassCanBeAutoloaded(): void
    {
        self::assertTrue(class_exists(Sdk::class));
    }
}
