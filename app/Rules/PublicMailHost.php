<?php

namespace App\Rules;

use App\Services\SafeHttp;
use App\Support\IpAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A page SMTP host set by a page administrator has to be on the public
 * internet. Otherwise Send test would tell them, host by host and port by
 * port, what listens on the server's own networks.
 */
class PublicMailHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::problem((string) $value);

        if ($problem !== null) {
            $fail($problem);
        }
    }

    /** Why the host may not be used, or null when it may. */
    public static function problem(string $host): ?string
    {
        // parse_url style brackets around an IPv6 literal are not part of the address.
        $host = trim(trim($host), '[]');
        $addresses = app(SafeHttp::class)->addresses($host);

        if ($addresses === []) {
            return "The SMTP host {$host} could not be resolved. Check the name.";
        }

        foreach ($addresses as $ip) {
            if (IpAddress::isInternal($ip)) {
                return "The SMTP host must be a public mail server. {$host} is on a private or local network, and only an installation administrator can use such a server here.";
            }
        }

        return null;
    }
}
