<?php

namespace App\Enums;

/** Values 1-4 match Cachet 2.x. */
enum IncidentStatus: int
{
    case Investigating = 1;
    case Identified = 2;
    case Watching = 3;
    case Resolved = 4;

    public function label(): string
    {
        return match ($this) {
            self::Investigating => 'Investigating',
            self::Identified => 'Identified',
            self::Watching => 'Watching',
            self::Resolved => 'Resolved',
        };
    }

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->name, $name) === 0) {
                return $case;
            }
        }
        throw new \ValueError("Unknown incident status [{$name}]");
    }
}
