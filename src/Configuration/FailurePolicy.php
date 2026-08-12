<?php

declare(strict_types=1);

namespace Calipso\Sdk\Configuration;

final class FailurePolicy
{
    public const FAIL = 'fail';
    public const THROW = 'throw';
    public const IGNORE = 'ignore';

    private function __construct() {}

    public static function isValid(string $policy): bool
    {
        return in_array($policy, [self::FAIL, self::THROW, self::IGNORE], true);
    }

    public static function shouldThrow(string $policy): bool
    {
        return $policy === self::FAIL || $policy === self::THROW;
    }
}
