<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class TrafficStatisticsRetention
{
    public const DAILY_DAYS = 365;
    public const HOURLY_DAYS = 30;

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today(config('app.timezone'));
    }

    public static function dailyStart(): CarbonImmutable
    {
        return self::today()->subDays(self::DAILY_DAYS - 1);
    }

    public static function hourlyStart(): CarbonImmutable
    {
        return self::today()->subDays(self::HOURLY_DAYS - 1);
    }
}
