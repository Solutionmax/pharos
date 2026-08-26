<?php

return [
    // Set at build time from the git tag; never bumped by hand in two places.
    'version' => env('PHAROS_VERSION', '0.1.0-dev'),

    // Ed25519 public key, hex. Signs both licence keys and update manifests,
    // with a different purpose field so one can never be replayed as the other.
    'license_public_key' => env('PHAROS_LICENSE_PUBLIC_KEY', ''),

    'update' => [
        // Where the signed manifest lives. Empty switches update checking off.
        'manifest_url' => env('PHAROS_UPDATE_URL', 'https://pharos.solutionmax.net/releases/latest.json'),
        'check_enabled' => env('PHAROS_UPDATE_CHECK', true),
        // Written by a host-side updater on a Docker install; absent on shared
        // hosting, which is how the app knows which world it is in.
        'status_file' => storage_path('app/ota/update-status.json'),
        'trigger_file' => storage_path('app/ota/update.trigger'),
    ],

    // Never hardcode these in views: moving to a real domain must stay a .env edit.
    'buy_url' => env('PHAROS_BUY_URL', 'https://solutionmax.net/pharos'),
    'docs_url' => env('PHAROS_DOCS_URL', 'https://pharos.solutionmax.net/docs'),
];
