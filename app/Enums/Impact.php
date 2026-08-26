<?php

namespace App\Enums;

/** Cachet has no impact field; SLA reporting and notification urgency need one. */
enum Impact: string
{
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
