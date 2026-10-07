<?php

namespace App\Services;

class DnsResolver
{
    public function records(string $name, string $type): array
    {
        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.?$/i', $name)) {
            throw new \RuntimeException('Invalid DNS name');
        }
        $flag = match ($type) {
            'A' => DNS_A,'AAAA' => DNS_AAAA,'CNAME' => DNS_CNAME,'MX' => DNS_MX,'TXT' => DNS_TXT,default => throw new \RuntimeException('Unsupported DNS record')
        };

        return array_slice(@dns_get_record($name, $flag) ?: [], 0, 100);
    }

    public function matches(string $type, string $expected, array $records): bool
    {
        $normal = fn ($s) => strtolower(rtrim(trim($s), '.'));
        foreach ($records as $record) {
            $actual = match ($type) {
                'A' => $record['ip'] ?? '', 'AAAA' => $record['ipv6'] ?? '', 'CNAME' => $record['target'] ?? '', 'MX' => ($record['pri'] ?? 0).' '.($record['target'] ?? ''), 'TXT' => isset($record['entries']) ? implode('', $record['entries']) : ($record['txt'] ?? ''),default => null
            };
            if ($actual === null) {
                return false;
            }
            if (in_array($type, ['A', 'AAAA'], true)) {
                if (@inet_pton($expected) !== false && @inet_pton($expected) === @inet_pton($actual)) {
                    return true;
                }
            } elseif ($type === 'TXT' ? $actual === $expected : $normal($actual) === $normal($expected)) {
                return true;
            }
        }

return false;
    }
}
