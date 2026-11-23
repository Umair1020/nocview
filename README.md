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

The four Sangfor firewalls (Clifton & University `103.246.109.194`, North Nazimabad
`103.246.109.2`, ZCR Boat Basin `103.246.109.74`, ZEB `103.246.109.82`) are listed
separately from MikroTik devices in `config.php` under `pingLocations`, each with its
monitored WAN interface (`eth5`, `eth4`, `eth4`, `eth3`) in `pingInterfaces`.

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


## 24/7 collection (no manual runs)

Sangfor bandwidth history used to depend on someone starting `snmp_pusher.php` again
and again (and on the office PC staying awake). Collection is now a service.

**Free option (no VPS, no PC left on):** `.github/workflows/sangfor-collector.yml` runs
`tools/sangfor_actions_poller.php` on GitHub Actions every 5 minutes, polls SNMP and posts
to `snmp_push.php`. Setup is 3 repository secrets; see
**`docs/free-24x7-github-actions.md`**.

**If you have an always-on machine (VPS or office PC):**

* **Poller** — `snmp_pusher.php --loop` runs forever (systemd unit or Windows task),
  polls ping + SNMP every 60 s and posts to `snmp_push.php` with a token.
* **Receiver** — `snmp_push.php` (now part of this repository) validates each push,
  stores the live values and appends one real measurement per site to
  `bandwidth_logs` every ~45 s, so 24h / 7d / 30d / 6m / 1y fill by themselves.
* **MikroTik history** — `collect_bandwidth.php --once` from cron (or `--loop`).
* **Liveness** — open `collector_status.php` (also `?format=json`) to see poller
  heartbeat, last push age and 24 h coverage per site.

```bash
php snmp_pusher.php --loop --interval=60 --log=/var/log/nocview/pusher.log
* * * * * /usr/bin/php /path/to/collect_bandwidth.php --once
```

Full setup, options and troubleshooting: **`docs/24x7-collector.md`**
(free GitHub Actions path: `docs/free-24x7-github-actions.md`).
Deployment helpers: `deploy/` (systemd units, crontab example, Windows installer).

Stale pushes are shown as last known good for up to 15 minutes instead of flipping a
site to "down", and frozen SNMP counters are reported as `N/A` instead of a false
`0 Mbps`.

Run checks with:
- `php tests/ping_location_test.php`
- `php tests/sangfor_config_test.php`
- `php tests/snmp_bandwidth_test.php`
- `php tests/push_receiver_test.php`
- `php tests/collector_common_test.php`
- `php tests/snmp_cli_test.php`
- `php tests/actions_poller_test.php`
