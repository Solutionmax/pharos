<?php

namespace App\Enums;

/**
 * Two roles, because a third one nobody asked for is a third one to reason about.
 * Admin owns the installation itself; user runs what the page reports on.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::User => 'User',
        };
    }
}
