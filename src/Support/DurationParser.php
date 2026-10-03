<?php

declare(strict_types=1);

namespace LogMonitor\Support;

use DateTimeImmutable;
use InvalidArgumentException;

final class DurationParser
{
    public function since(string $duration): DateTimeImmutable
    {
        if (! preg_match('/^([1-9]\d*)([smhd])$/', $duration, $match)) {
            throw new InvalidArgumentException('Use a positive duration such as 30m, 1h, 24h, or 7d.');
        }
        $seconds = (int) $match[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$match[2]];
        if ($seconds > 366 * 86400) {
            throw new InvalidArgumentException('The maximum inspection window is 366 days.');
        }

        return new DateTimeImmutable('-'.$seconds.' seconds');
    }
}
