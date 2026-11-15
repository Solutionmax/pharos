<?php

namespace App\Http\Controllers\PublicFeatures;

use App\Http\Controllers\Controller;
use App\Services\InstallSettings;
use App\Services\Localization;

/**
 * /.well-known/security.txt (RFC 9116): where to report a vulnerability in this
 * installation. Only there once an administrator filled in a security contact
 * under Settings, General; without one the address is a plain 404.
 */
class SecurityTxtController extends Controller
{
    public function __invoke()
    {
        $contact = InstallSettings::securityContact();
        abort_if($contact === '', 404);

        $lines = [
            'Contact: '.(str_contains($contact, '@') && ! str_starts_with(strtolower($contact), 'https://') ? 'mailto:'.$contact : $contact),
            // Midnight a year from today: the same answer all day, never past the year RFC 9116 recommends.
            'Expires: '.now('UTC')->addYear()->startOfDay()->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: '.implode(', ', array_keys(Localization::LANGUAGES)),
            'Canonical: '.rtrim((string) config('app.url'), '/').'/.well-known/security.txt',
        ];

        return response(implode("\n", $lines)."\n")
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
