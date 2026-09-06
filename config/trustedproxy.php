<?php

return [
    // Laravel reads this after .env/config loading. Reading env() while building
    // bootstrap/app.php misses values from .env and from the configuration cache.
    // An empty list keeps untrusted clients from supplying forwarded identities.
    'proxies' => trim((string) env('TRUSTED_PROXIES', '')) ?: [],
];
