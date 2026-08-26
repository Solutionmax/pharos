<?php

namespace App\Enums;

enum CheckType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
    case Heartbeat = 'heartbeat';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP GET',
            self::Tcp => 'TCP port',
            self::Heartbeat => 'Heartbeat',
        };
    }
}
