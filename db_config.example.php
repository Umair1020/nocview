<?php
// File: db_config.example.php
// Copy this file on the server (never commit the copy):
//   cp db_config.example.php db_config.local.php && chmod 600 db_config.local.php
//
// db_config.local.php is git-ignored and denied by .htaccess. Any key may be
// omitted; the built-in default in db_connect.php is used for missing keys.
return [
    'host'     => 'localhost',
    'username' => 'u123_nocview',
    'password' => 'REPLACE-WITH-THE-DATABASE-PASSWORD',
    'database' => 'u123_network_master',
];
