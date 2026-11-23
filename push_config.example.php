<?php
// File: push_config.example.php
// Copy this file on the server (never commit the copy):
//   cp push_config.example.php push_config.local.php && chmod 600 push_config.local.php
//
// push_config.local.php is git-ignored and denied by .htaccess, so the token
// never ends up in the repository or on the wire to browsers.
return [
    // 'receive' = this website accepts pushes from a poller and uses the values
    // 'push'    = this machine IS a poller; results are POSTed to the URL
    // 'off'     = push mode disabled entirely (endpoint answers 403)
    'mode' => 'receive',

    // Long random token. Use the SAME value in the GitHub secret NOCVIEW_PUSH_TOKEN
    // (or in the poller's own push_config.local.php) — e.g.:
    //   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
    'token' => 'REPLACE-WITH-A-LONG-RANDOM-TOKEN',

    // How long a push is served as "live" (seconds). 420 = 7 minutes,
    // so a 5-minute GitHub Actions cadence never looks stale.
    'live_max_age' => 420,

    // How long a stale push is served as "last known good" (seconds).
    'grace_seconds' => 1800,
];
