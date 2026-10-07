<?php

return ['web_cron_token' => env('PHAROS_WEB_CRON_TOKEN'), 'probe_hub' => env('PHAROS_PROBE_HUB'), 'probe_token' => env('PHAROS_PROBE_TOKEN'), 'webauthn_origin' => env('PHAROS_WEBAUTHN_ORIGIN', env('APP_URL', 'http://localhost'))];
