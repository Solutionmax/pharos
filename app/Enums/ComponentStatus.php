<?php

namespace App\Enums;

/** Values 1-4 match Cachet 2.x so imported data and existing API clients keep working. */
enum ComponentStatus: int
{
    case Operational = 1;
    case PerformanceIssues = 2;
    case PartialOutage = 3;
    case MajorOutage = 4;
    case UnderMaintenance = 5;

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Operational',
            self::PerformanceIssues => 'Degraded performance',
            self::PartialOutage => 'Partial outage',
            self::MajorOutage => 'Major outage',
            self::UnderMaintenance => 'Under maintenance',
        };
    }

    /** CSS modifier used by the status page and the uptime bars. */
    public function tone(): string
    {
        return match ($this) {
            self::Operational => 'ok',
            self::PerformanceIssues => 'w',
            self::PartialOutage => 'p',
            self::MajorOutage => 'b',
            self::UnderMaintenance => 'm',
        };
    }

    public function isDown(): bool
    {
        return in_array($this, [self::PartialOutage, self::MajorOutage], true);
    }
}
