<?php

return [
    // Set at build time from the git tag; never bumped by hand in two places.
    // The number that ships with the code. An update replaces this file, which is
    // how an install learns its new version — .env is deliberately never replaced,
    // so a value pinned there would survive the upgrade and make the same release
    // be offered for ever. The override exists for Docker, where the host owns the
    // image and the app never updates itself.
    'version' => env('PHAROS_VERSION', '0.1.0-dev'),

    // Ed25519 public key, hex. Signs both licence keys and update manifests,
    // with a different purpose field so one can never be replayed as the other.
    //
    // Shipped as the default on purpose: this is the public half, and a customer
    // who has to paste it into .env before their paid key verifies has a broken
    // install that looks like a broken licence. The env var stays so a fork can
    // sign with its own keypair. `?:` rather than env()'s own default: a bare
    // `PHAROS_LICENSE_PUBLIC_KEY=` line left in .env is "" and "" is a value,
    // so the default would never apply and every paid key would be refused.
    'license_public_key' => env('PHAROS_LICENSE_PUBLIC_KEY')
        ?: '68d158ba363853e3b64efa7c2082015d198db31a4a039f05a24d3bbd93308ff2',

    'update' => [
        // Where the signed manifest lives. Empty switches update checking off.
        'manifest_url' => env('PHAROS_UPDATE_URL', 'https://pharos.solutionmax.net/releases/latest.json'),
        'check_enabled' => env('PHAROS_UPDATE_CHECK', true),
        // Written by a host-side updater on a Docker install; absent on shared
        // hosting, which is how the app knows which world it is in.
        'status_file' => storage_path('app/ota/update-status.json'),
        'trigger_file' => storage_path('app/ota/update.trigger'),
        // Where a self-update parks the version it replaces. Never pruned by the app.
        'backups_dir' => storage_path('app/backups'),
        // What "Back up now" copies. Null means the application itself; only the
        // test suite points it at a small stand-in tree.
        'backup_source' => null,
    ],

    // How long the audit trail keeps a line. Long enough to answer "who
    // changed that, and when" months later; short enough that the table does
    // not grow without end on shared hosting.
    'audit_days' => (int) env('PHAROS_AUDIT_DAYS', 180),

    // Never hardcode these in views: moving to a real domain must stay a .env edit.
    'buy_url' => env('PHAROS_BUY_URL', 'https://solutionmax.net/pharos'),
    'docs_url' => env('PHAROS_DOCS_URL', 'https://pharos.solutionmax.net/docs'),
];
