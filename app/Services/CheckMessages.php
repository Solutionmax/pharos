<?php

namespace App\Services;

use App\Models\Check;

/**
 * What a check writes on the incident it opens and closes. Visitors read it on
 * the status page, so it names the service and says what went wrong in plain
 * words rather than in the probe's. Written in the page's language at that
 * moment, like the incident title; an incident keeps the text it was given.
 */
class CheckMessages
{
    /** The probe's reason (see Probe and ProbeQuorum) → what a visitor reads. */
    public static function opened(Check $check, ProbeResult $result): string
    {
        $reason = (string) $result->message;
        $replace = ['service' => $check->component->name, 'seconds' => $check->timeout_seconds];

        if (preg_match('/^HTTP (\d{3})$/', $reason, $match) === 1) {
            return self::say(':service answered with an error (HTTP :code). We are looking into it.', $replace + ['code' => $match[1]]);
        }

        $unreachable = fn (string $why) => self::say(':service could not be reached (:reason). We are looking into it.', $replace + ['reason' => self::say($why)]);

        return match (true) {
            stripos($reason, 'timed out') !== false => self::say(':service did not answer within :seconds seconds. We are looking into it.', $replace),
            in_array($reason, ['Name does not resolve', 'DNS lookup failed', 'Target cannot be resolved or is invalid'], true) => $unreachable('its address could not be found'),
            str_starts_with($reason, 'TLS certificate details') || $reason === 'TLS certificate problem' => $unreachable('a problem with its security certificate'),
            stripos($reason, 'refused') !== false => $unreachable('the connection was refused'),
            $reason === 'No response' => self::say(':service did not respond. We are looking into it.', $replace),
            $reason === 'Expected text is missing' => self::say(':service answered, but without the content we expect. We are looking into it.', $replace),
            $reason === 'Response exceeds 1 MiB' => self::say(':service answered with far more data than expected. We are looking into it.', $replace),
            $reason === 'Expected DNS record is missing' => self::say('The DNS record of :service is not what it should be. We are looking into it.', $replace),
            $reason === 'Never reported in' || str_starts_with($reason, 'No heartbeat since') => self::say(':service did not check in on time. We are looking into it.', $replace),
            $reason === 'Location quorum failed' => self::say(':service could not be reached from most of our check locations. We are looking into it.', $replace),
            // An HTTP error while the certificate is close to expiry carries the expiry, not the code.
            str_starts_with($reason, 'TLS certificate expires') => self::say(':service answered with an error. We are looking into it.', $replace),
            str_starts_with($reason, 'Target ') => self::say(':service could not be checked. We are looking into it.', $replace),
            default => self::say(':service could not be reached. We are looking into it.', $replace),
        };
    }

    public static function resolved(Check $check, int $streak): string
    {
        $replace = ['service' => $check->component->name, 'n' => $streak];

        return $streak > 1
            ? self::say(':service is working again and has stayed up for :n checks in a row.', $replace)
            : self::say(':service is working again.', $replace);
    }

    /** @param  array<string, mixed>  $replace */
    private static function say(string $key, array $replace = []): string
    {
        return __($key, $replace, Localization::page());
    }
}
