# 24/7 bandwidth collection (Sangfor + MikroTik) — no manual runs

**Short Roman Urdu:** ab script ko bar-bar chalane ki zaroorat nahi. Sab se sasta
rasta: **GitHub Actions free** (na VPS, na PC on) — 5 minute me khud chalta hai aur
data website par bhejta hai (`docs/free-24x7-github-actions.md`). Agar aap ke paas
hamesha on rehne wali machine (VPS ya office PC) hai to wohi poller `--loop` mode me
chalayein: aik dafa start, khud restart, har 60 second me charon Sangfor locations ka
ping + bandwidth website ko.
Website (`snmp_push.php`) har push par `bandwidth_logs` me sample likhta hai, is liye
MikroTik ki tarah 24h / 7d / 30d / 6m / 1y graphs apne aap bharte rehte hain. Status
dekhne ke liye `collector_status.php` kholain — terminal ya dashboard kholna zaroori nahi.

---

## 1. Why it had to be started again and again

Three separate things were stopping history, and none of them were the dashboard:

| Symptom (from `pusher.log`) | Cause |
| --- | --- |
| Data stops whenever the office PC sleeps / reboots / someone logs off | The collector was started by hand or by a per-minute scheduled script. Nothing restarted it, and `sys_get_temp_dir()` counter state was lost on reboot. |
| `logged=0` on most pushes | The receiver only accepted a sample every 45 s while the pusher pushed every 15 s, so 3 of every 4 measurements were dropped. |
| `snmp_waiting_refresh` / `0/0` at ZCR Boat Basin | Sangfor refreshes IF-MIB counters slowly. After 120 s of unchanged counters the rate was computed as **0.0 Mbps** — the site looked "down" although it was busy. |
| Sites went "Unknown" for a few minutes | Pushed data older than 150 s was treated as no data at all, so every hiccup looked like an outage. |
| The receiver endpoint was not in the repository | `snmp_push.php` existed only on the server. A redeploy or a new host silently removed the whole ingest path. |

## 2. What the fix does

```
        ┌──────────────────────────────┐        HTTPS POST + token        ┌─────────────────────────┐
        │ Poller (VPS or office PC)    │ ───────────────────────────────▶ │ Website (Hostinger)      │
        │ snmp_pusher.php --loop       │   one update / 60 s / site       │ snmp_push.php (receiver) │
        │  • ICMP ping                 │                                  │  • validates + throttles │
        │  • SNMP ifHCIn/ifHCOutOctets │ ◀── nothing needed back ───────  │  • cache for live tiles  │
        │  • counters kept on disk     │                                  │  • 1 history row/45 s    │
        └──────────────────────────────┘                                  └───────────┬─────────────┘
                                                                                      │
   MikroTik routers ◀── collect_bandwidth.php (cron every minute or --loop) ──────────┤
                                                                                      ▼
                                                              bandwidth_logs → 24h / 7d / 30d / 6m / 1y
```

* **One process, no babysitting.** `--loop` polls forever, keeps the SNMP counter
  baselines on disk (survives reboots), and systemd / Task Scheduler restart it
  automatically. It never needs a dashboard open.
* **History is written on the website, not in a browser.** `snmp_push.php` is now part
  of the repository, authenticates every request, and appends one real measurement
  per site per ~45 s. That is the same cadence as the MikroTik cron, so all ranges fill.
* **No more fake zeros.** Frozen counters are reported as `snmp_waiting_refresh`
  (bandwidth `N/A`) until the agent refreshes, or as the last real value for up to
  5 minutes. Only 15+ minutes of identical counters is treated as an idle link (0 Mbps).
* **Brief hiccups are not outages.** Up to 15 minutes old data is served as
  *last known good* with a `stale` flag. Nothing stale is ever written to history.
* **You can see it is alive.** Heartbeat files + `collector_status.php` show poller
  state, last push age, last stored sample, and 24 h coverage per site.

## 3. Pick where the poller lives

Hostinger shared hosting cannot send SNMP (outbound UDP 161 blocked), so *something*
always-on has to poll the four Sangfor firewalls. Three supported layouts:

### Option 0 — free, no PC, no VPS: GitHub Actions (**no budget**)

GitHub runs `tools/sangfor_actions_poller.php` every 5 minutes, polls the firewalls and
posts to `snmp_push.php`. Nothing has to stay switched on. Setup is three repo secrets +
merging `.github/workflows/sangfor-collector.yml` to the default branch.
Full guide: **`docs/free-24x7-github-actions.md`**.

* Cadence: 5 minutes → ~288 history rows/day/site (24h chart uses 5-minute buckets, so it
  still looks complete).
* Website `push_config.local.php`: `'live_max_age' => 420`, `'grace_seconds' => 1800`.
* Cost: 0 (≈290 of the 2,000 free private-repo Actions minutes per month).
* Caveat: works only if the Sangfor firewalls answer SNMP to GitHub's IPs. The workflow's
  `probe` run tells you in one minute; if it says `snmp_unreachable`, use Option A or B.

### Option A — small always-on VPS

No dependency on the office PC, on XAMPP, or on anyone logging in.

```bash
# on the VPS (Ubuntu/Debian), as root
apt-get update && apt-get install -y php-cli php-snmp php-curl snmp iputils-ping rsync
useradd --system --home /opt/nocview --shell /usr/sbin/nologin nocview || true
mkdir -p /opt/nocview /var/lib/nocview /var/log/nocview
rsync -a --exclude '.git' ./  nocview@vps:/opt/nocview/

# credentials, server-only (never commit)
cat > /opt/nocview/snmp_config.local.php <<'PHP'
<?php
return [
    '103.246.109.194' => ['community' => 'READONLY-COMMUNITY', 'interface' => 'eth5'],
    '103.246.109.2'   => ['community' => 'READONLY-COMMUNITY', 'interface' => 'eth4'],
    '103.246.109.74'  => ['community' => 'READONLY-COMMUNITY', 'interface' => 'eth4'],
    '103.246.109.82'  => ['community' => 'READONLY-COMMUNITY', 'interface' => 'eth3'],
];
PHP

cat > /opt/nocview/push_config.local.php <<'PHP'
<?php
return [
    'mode'  => 'push',
    'token' => 'SAME-TOKEN-AS-THE-WEBSITE',
    'url'   => 'https://nocview.spotcommglobal.com/snmp_push.php',
];
PHP

chown -R nocview:nocview /opt/nocview /var/lib/nocview /var/log/nocview
chmod 600 /opt/nocview/*.local.php

# dry run first: prints what would be sent, sends nothing
sudo -u nocview php /opt/nocview/snmp_pusher.php --dry-run --loop --interval=60 --ticks=2

# install as a service (restarts itself on crash and after reboot)
cp /opt/nocview/deploy/nocview-collector.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now nocview-collector
journalctl -u nocview-collector -f
```

If this VPS hosts NOCView itself (MySQL + Apache on the same box), also use
`deploy/nocview-history-collector.service` for MikroTik history instead of cron.
When the VPS can reach UDP 161 you may skip push mode entirely (`mode => 'off'`)
and let `collect_bandwidth.php --loop` poll everything directly — but keep push
mode if the website stays on Hostinger.

### Option B — office PC / XAMPP, as a Windows service

Works without any new server, but **the PC must stay on**. The collector starts at
boot, survives logoff, and Windows restarts it after a crash:

```powershell
# PowerShell as Administrator
cd C:\xampp\htdocs\nocview\deploy
.\install-windows-task.ps1                 # at boot + every minute restart
Get-ScheduledTaskInfo -TaskName 'NOCView Sangfor Collector'
Get-Content C:\xampp\htdocs\nocview\tmp\pusher.log -Tail 20 -Wait
```

`.\install-windows-task.ps1 -Uninstall` removes it. In this mode the collector does
not depend on an RDP session, the browser, or the tray icon; only on Windows being
powered on. If the PC sleeps, configure *Power Options → Sleep: Never* for mains power,
or prefer Option A.

> The old per-minute `run_pusher_hidden.vbs` shortcut still works (it runs the legacy
> single-run mode), but it cannot keep one process alive and it drops the 15 s ticks.
> Replace it with the scheduled task above. If Task Scheduler is not an option, double
> click `deploy\run-pusher-loop.bat`: it keeps one `--loop` process alive and restarts
> it 10 s after a crash (still needs the PC to stay on).

### Both options — website side

1. Copy `push_config.example.php` to `push_config.local.php` **on the website** with
   `'mode' => 'receive'` and the same token, then `chmod 600`. It is git-ignored and
   blocked by `.htaccess`.
2. Keep `.htaccess` deployed: it denies `*.local.php`, `snmp_push_cache.json`,
   `*_heartbeat.json`, `*poller.json` and `*.log`, and turns off directory listing.
3. MikroTik history keeps using cron (or `--loop` on a VPS):
   `* * * * * /usr/bin/php /home/USER/public_html/collect_bandwidth.php --once`
   See `deploy/crontab.example`.
4. Make sure `tmp/` and `cache/` are writable by PHP (heartbeats, SNMP state):
   `mkdir -p tmp cache && chmod 775 tmp cache`.

## 4. Verify in 30 seconds

Open `https://<site>/collector_status.php` (or `.../collector_status.php?format=json`):

* **Poller** = `live`, last tick < 2 minutes, source "last push from <host>".
* **History collector** = `live` (cron runs every minute).
* Every Sangfor site: `Pushed` = `live`, `Samples 1h` ≈ 45–60, `Coverage 24h` near 100 %.
* `Checks` lists anything that is lagging — no guessing needed.

Expected steady state per Sangfor site: **~1,440 history rows/day**
(one per minute, 45 s dedupe). The 24h chart then has a point for every bucket,
and 7d/30d/6m/1y are averages of those rows.

## 5. Troubleshooting

| Status / log line | Meaning | Action |
| --- | --- | --- |
| `push_stale` in the API, site Unknown | No push for 15+ minutes | Check the poller service (`systemctl status nocview-collector`, Task Scheduler, `tmp/pusher.log`). |
| `stale` badge, values still shown | Push is 2.5–15 min old | Network/VPN flap; data resumes by itself. |
| `HTTP 401` / `HTTP 403` | Token or mode mismatch | Token must be identical in both `push_config.local.php` files; website must be `receive`. |
| `HTTP 404` | Receiver not deployed | `snmp_push.php` must exist next to `config.php` (it is now part of the repo). |
| `poller heartbeat: stopped` | Daemon not running | `systemctl restart nocview-collector` / re-run the Windows installer. |
| `history collector heartbeat: stopped` | Cron missing or PHP fatal | Check the cPanel cron job and `tmp/collect.log`. |
| Site shows `snmp_waiting_refresh` | Firewall agent has not refreshed counters yet | Normal for a few minutes after a firewall reboot; if permanent, verify the community string and interface name. |
| `coverage 24h` far below 100 % | Poller stops overnight (PC sleep) | Use Option A, or disable sleep in Windows power settings. |
| `interface_not_found` | Wrong interface name for that Sangfor model | Fix `pingInterfaces` in `config.php`; `snmpwalk -v2c -c COMMUNITY IP 1.3.6.1.2.1.31.1.1.1.1` lists real names. |

## 6. Security notes

* `push_config.local.php` and `snmp_config.local.php` are git-ignored, denied by
  `.htaccess`, and should be `chmod 600`. Only the token **hash comparison** is
  constant-time (`hash_equals`); an empty token disables the endpoint.
* `snmp_push.php` accepts only POST, only configured Sangfor IPs, at most 64 KB and
  64 results per request, and throttles each site to one live update per 3 s.
  Rates are range-checked; anything odd is stored as `null`, never as `0`.
* If the token was ever committed or shared, rotate it on both sides:
  `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"` → paste into both
  `push_config.local.php` files → `gh secret set NOCVIEW_PUSH_TOKEN --body "<new>"`
  → `systemctl restart nocview-collector`.
  **This applies today:** a real receiver token was accidentally committed to the
  repository in the initial import. The file has been removed from the repo, but
  treat the old token as compromised and rotate it (see README → "Security").
* The SNMP community must stay **read-only**. Do not reuse firewall or MikroTik
  login passwords.

## 7. Files involved

| File | Role |
| --- | --- |
| `snmp_push.php` | **new** receiver endpoint: auth, validation, throttling, history write |
| `push_ingest_lib.php` | **new** pure validation/ingest rules (unit tested) |
| `push_lib.php` | cache, freshness (live / stale / grace), atomic store, poller meta |
| `snmp_pusher.php` | poller; `--loop` daemon, heartbeat, retries, log rotation, `--dry-run` |
| `collect_bandwidth.php` | history collector; `--loop` daemon + `--once` cron mode, heartbeat |
| `collector_common.php` | **new** shared CLI/log/heartbeat/lock helpers |
| `collector_status.php` | **new** liveness + coverage page (HTML and JSON) |
| `deploy/*` | systemd units, crontab example, Windows task installer |
| `tools/sangfor_actions_poller.php` | **new** free GitHub Actions poller (two SNMP samples + POST) |
| `.github/workflows/sangfor-collector.yml` | **new** 5-minute schedule + probe button |
| `tests/push_receiver_test.php`, `tests/collector_common_test.php`, `tests/snmp_cli_test.php` | **new** tests |

Run the whole suite:

```bash
php tests/ping_location_test.php
php tests/sangfor_config_test.php
php tests/snmp_bandwidth_test.php
php tests/push_receiver_test.php
php tests/collector_common_test.php
php tests/snmp_cli_test.php
php tests/actions_poller_test.php
```
