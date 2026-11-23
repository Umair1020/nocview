<?php
// File: push_config.example.php
// Copy to push_config.local.php and fill in a long random token. That file is
// git-ignored and blocked by .htaccess - never commit the real token.
//
// Generate a token once, then use the SAME value on both sides:
//   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
//
// On the website (Hostinger - cannot reach UDP 161):
//   return ['mode' => 'receive', 'token' => 'PASTE-THE-SAME-TOKEN'];
//
// On the poller (always-on VM or office PC that can reach the Sangfor firewalls):
//   return [
//       'mode' => 'push',
//       'token' => 'PASTE-THE-SAME-TOKEN',
//       'url' => 'https://nocview.spotcommglobal.com/snmp_push.php',
//       'loop_interval_sec' => 60,   // daemon tick (--loop)
//       'interval_sec' => 15,        // legacy scheduled-run tick
//       'runs_per_start' => 4,       // legacy scheduled-run ticks per start
//   ];

return [
    'mode' => 'receive',
    'token' => 'replace-with-a-long-random-token',
];
