<?php

declare(strict_types=1);

namespace Calipso\Sdk\Configuration;

final class FailurePolicy
{
    public const THROW = 'throw';
    public const IGNORE = 'ignore';

    private function __construct() {}

    public static function isValid(string $policy): bool
    {
        return in_array($policy, [self::THROW, self::IGNORE], true);
    }
}
