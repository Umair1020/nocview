# routeros-api
Client API for RouterOS/Mikrotik

This class was originally written by Denis Basta and updated by several contributors.  It aims to give a simple interface to the RouterOS API in PHP.

The old Mikrotik Wiki page is found on archive.org https://web.archive.org/web/20170210102259/http://wiki.mikrotik.com/wiki/API_PHP_class

## Contributors (before moving to Git)
* Nick Barnes
* Ben Menking (ben [at] infotechsc [dot] com)
* Jeremy Jefferson (http://jeremyj.com)
* Cristian Deluxe (djcristiandeluxe [at] gmail [dot] com)
* Mikhail Moskalev (mmv.rus [at] gmail [dot] com)

## Changelog

Please see git logs.  Version 1.0 through current version have been preserved in this Git repo.

require('routeros_api.class.php');
## Clifton Sangfor monitoring

Ziauddin Hospital Clifton & University (`103.246.109.194`) is listed separately
from MikroTik devices in `config.php` under `pingLocations`, with its monitored
WAN interface set to `ether1` under `pingInterfaces`.

- **Reachability:** The live dashboard, Locations and heatmap check ICMP
  reachability from the NOCView server (a reply is **Up**; no reply or an
  unavailable `ping` binary is **Unknown**, not a confirmed outage).
- **Bandwidth (SNMPv2c, read-only):** `router_poll.php`, `bandwidth.php`, and
  `collect_bandwidth.php` poll standard IF-MIB counters (`ifName`/`ifDescr` and
  `ifHCInOctets`/`ifHCOutOctets`, falling back to 32-bit counters) for `ether1`
  when PHP's `snmp` extension is enabled and a read-only SNMPv2c community is
  configured on the server. When SNMP is not configured, blocked, priming its
  first counter sample, or `ether1` is absent on the firewall, bandwidth is
  reported as `N/A` rather than `0`.
- **Server-only SNMP configuration (never commit credentials):** Create an
  untracked `snmp_config.local.php` on the server (ignored by `.gitignore` and
  blocked by `.htaccess`) or set `NOCVIEW_SNMP_COMMUNITY`:

  ```php
  <?php
  return [
      '103.246.109.194' => [
          'community' => 'replace-with-readonly-snmp-community',
          // Optional overrides: 'port' => 161, 'timeout_ms' => 1500, 'retries' => 1, 'interface' => 'ether1'
      ],
  ];
  ```

  Do not use firewall web-login passwords or MikroTik credentials as SNMP
  community strings, and never commit secrets to source code.

Run checks with:
- `php tests/ping_location_test.php`
- `php tests/sangfor_config_test.php`
- `php tests/snmp_bandwidth_test.php`
