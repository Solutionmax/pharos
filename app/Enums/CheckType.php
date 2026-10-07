<?php

namespace App\Enums;

enum CheckType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
    case Dns = 'dns';
    case Heartbeat = 'heartbeat';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP GET',
            self::Tcp => 'TCP port',
            self::Dns => 'DNS record',
            self::Heartbeat => 'Heartbeat',
        };
    }
}
