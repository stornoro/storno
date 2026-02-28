<?php

namespace App\Constants;

final class Pagination
{
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 20;

    public static function clamp(int $requested, int $default = self::DEFAULT_LIMIT): int
    {
        return min(max($requested, 1), self::MAX_LIMIT) ?: $default;
    }
}
