# Free 24/7 collection — GitHub Actions (no VPS, no PC on)

**Roman Urdu:** VPS ke paise ki zaroorat nahi. GitHub Actions (free) har 5 minute me
charon Sangfor firewalls se SNMP counters parhta hai, RX/TX nikalta hai aur website ke
`snmp_push.php` par bhej deta hai — bilkul waisay jaise MikroTik ki locations ka data
cron se save hota hai. Aap ka PC band ho, laptop band ho, browser band ho — data store
hota rehta hai. Bas repo me ye workflow upload karna hai aur 3 secrets daalne hain.

---

## 1. How it works

```
GitHub Actions (free runner)          every 5 minutes
   ├─ snmpwalk/snmpget  → 4 Sangfor firewalls (baseline counters)
   ├─ wait 20 s         → read counters again
   ├─ compute Mbps + ICMP ping
   └─ HTTPS POST + token → https://<site>/snmp_push.php
                              ├─ live values  → dashboard tiles
                              └─ 1 history row per site → bandwidth_logs
                                                          → 24h / 7d / 30d / 6m / 1y
```

* Each run takes its **own two samples**, so nothing has to survive between runs —
  perfect for CI runners that start fresh every time.
* The receiver is the same `snmp_push.php` used by the PC pusher. If you later get a
  VPS or want to use the office PC again, only the sender changes; history and charts
  stay identical.
* One run ≈ 50–70 seconds. Every 5 minutes = **~290 Actions minutes/month**; free plan
  gives 2,000 minutes for private repos and unlimited for public repos.

## 2. Setup (one time, ~5 minutes)

### 2.1 Website must be ready first

1. Upload the repo's PHP files to Hostinger (they include `snmp_push.php`, the receiver).
2. `push_config.local.php` on the website:
   ```php
   return [
       'mode' => 'receive',
       'token' => 'PASTE-A-LONG-RANDOM-TOKEN',
       // 5-minute cadence: keep the last measurement "live" for 7 minutes
       'live_max_age' => 420,
       'grace_seconds' => 1800,
   ];
   ```
3. `chmod 600 push_config.local.php` (it is git-ignored and blocked by `.htaccess`).

### 2.2 Add three repository secrets

GitHub → your repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Name | Value |
| --- | --- |
| `NOCVIEW_PUSH_URL` | `https://nocview.spotcommglobal.com/snmp_push.php` |
| `NOCVIEW_PUSH_TOKEN` | the same token you put on the website |
| `NOCVIEW_SNMP_COMMUNITIES` | `{"103.246.109.194":"COMMUNITY","103.246.109.2":"COMMUNITY","103.246.109.74":"COMMUNITY","103.246.109.82":"COMMUNITY"}` |

`NOCVIEW_SNMP_COMMUNITIES` is one JSON line: IP → read-only SNMP community for each
Sangfor firewall. Different communities per firewall are fine.

Generate the token once and use the same value on both sides:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

Or with the GitHub CLI:

```bash
gh secret set NOCVIEW_PUSH_URL      --body "https://nocview.spotcommglobal.com/snmp_push.php"
gh secret set NOCVIEW_PUSH_TOKEN    --body "PASTE-THE-SAME-TOKEN"
gh secret set NOCVIEW_SNMP_COMMUNITIES --body '{"103.246.109.194":"COMM","103.246.109.2":"COMM","103.246.109.74":"COMM","103.246.109.82":"COMM"}'
```

### 2.3 Merge the workflow to the default branch

GitHub only runs scheduled workflows from the **default branch** (`master` here).
The file is `.github/workflows/sangfor-collector.yml`. After merging:

* Actions tab → **Sangfor bandwidth collector** → it will run at minutes 2, 7, 12, …
* First test: **Run workflow ▸ probe = true**. The log prints one line per site:
  * `SNMP OK (counters read, baseline primed)` → perfect, the firewall answered.
  * `snmp_unreachable` → GitHub's IP is not allowed by that firewall (see §5).
  * `interface_not_found` → wrong interface name in `config.php`.
  * `snmp_not_configured` → the IP is missing from `NOCVIEW_SNMP_COMMUNITIES`.

Then run it normally (probe = false) or wait for the schedule, and open
`https://<site>/collector_status.php`.

## 3. What you should see

| Where | Expected |
| --- | --- |
| Actions run log | `HTTP 200 {"ok":true,"stored":4,"logged":4,...}` and per-site `79.16/7.95 Mbps` |
| `collector_status.php` | Poller **live**, source `last push from github-actions`, mode `actions`, interval `300s` |
| Same page, per site | `Pushed` = `live`, `Samples 1h` ≈ 11–12, `Coverage 24h` ≈ 100 % |
| Bandwidth page (24h) | A point in every 5-minute bucket — no gaps |
| `bandwidth_logs` | ~288 rows/day/site (vs 1,440 with a 1-minute poller) |

The 24h chart groups samples into 5-minute buckets (`BW_RANGES` in `bandwidth_lib.php`),
so a 5-minute collector fills it exactly. 7d / 30d / 6m / 1y are averages of the same
rows and look identical to the MikroTik locations.

## 4. Good to know

* **Cadence:** GitHub's shortest cron is 5 minutes, and scheduled runs can be delayed a
  few minutes under load. That is fine for graphs and for `push_stale` detection, because
  `live_max_age` is 420 s and `grace_seconds` is 30 min.
* **Minutes:** ~290/month of the 2,000 free private-repo minutes. A public repo is
  unlimited. You can lower usage by changing the cron to `*/10 * * * *`.
* **Inactivity:** GitHub pauses scheduled workflows after 60 days without repository
  activity; any push (or one manual run) re-enables them. If you want extra safety, run
  the workflow manually once a month.
* **No PC needed:** once this is merged, the office PC, XAMPP and the old
  `run_pusher.bat` task can all stay off.
* **MikroTik history** still comes from cron (`collect_bandwidth.php --once`) — that part
  already works and is unchanged.
* **ICMP:** runners can usually ping, so latency is reported too. If your firewall blocks
  ICMP from the internet, set `NOCVIEW_SKIP_ICMP: '1'` in the workflow `env:` block — SNMP
  status is still reported (site stays `up`) and the run gets faster.
* Prefer 1-minute data later? Run the same poller on the office PC
  (`snmp_pusher.php --loop`) or a VPS and remove this workflow; the receiver does not care
  who sends.

## 5. If the probe says `snmp_unreachable`

Sangfor firewalls usually accept SNMP only from allowed addresses. GitHub runners use
large, changing IP ranges (published at `api.github.com/meta`), so allow-listing them is
impractical. Options, cheapest first:

1. **Ask whoever manages the Sangfor ACL** to allow SNMP from the runner range — usually
   not practical, but if SNMP is open to the internet and the community is correct, this
   is not the problem: check that UDP 161 is not filtered by the ISP and that the
   community in `NOCVIEW_SNMP_COMMUNITIES` exactly matches the firewall.
2. **Oracle Cloud Always Free VM** — genuinely free forever (no monthly bill), arm64
   instance in the Mumbai/Hyderabad region. Allow-list its single static IP on the four
   firewalls and run `snmp_pusher.php --loop --interval=60` there (see
   `docs/24x7-collector.md`, Option A). You get 1-minute data as a bonus.
3. **MikroTik script** — if one of your MikroTik routers is always on inside your own
   network, RouterOS v7 can poll SNMP and POST with `/tool fetch`. Ask and this can be
   added as `tools/routeros-poller.rsc`.

Everything else (receiver, history, status page, troubleshooting) is shared by all three
options, so switching later does not lose data.

## 6. Troubleshooting (Actions specific)

| Log | Meaning | Fix |
| --- | --- | --- |
| `HTTP 401` | Token mismatch | Same token in `NOCVIEW_PUSH_TOKEN` secret and website `push_config.local.php`. |
| `HTTP 403` | Website still in `push` mode | Set `'mode' => 'receive'` on the website. |
| `HTTP 404` | `snmp_push.php` not uploaded | Upload the repo files to Hostinger. |
| `HTTP 503` | Website MySQL down | Check the hosting DB; the run fails on purpose so you notice. |
| `Neither the PHP snmp extension nor net-snmp binaries …` | `snmp` package missing | The workflow installs it; a self-hosted runner needs `apt-get install snmp`. |
| `stored:0 rejected:[throttled]` | Two runs overlapped | Harmless; the second one is deduplicated. |
| Run red, log says nothing | Forked/private repo without Actions minutes | Check Billing → Actions. |

## 7. Files involved

| File | Role |
| --- | --- |
| `.github/workflows/sangfor-collector.yml` | schedule (every 5 min) + manual probe |
| `tools/sangfor_actions_poller.php` | CLI poller: SNMP twice, optional ping, POST to the receiver |
| `tools/actions_lib.php` | reusable two-sample collect + POST logic (unit tested) |
| `snmp_push.php` | receiver (unchanged by this option) |
| `router_poll.php` | net-snmp binary fallback when PHP's `snmp` extension is absent |
| `push_config.local.php` | `live_max_age` / `grace_seconds` tune the live window |
| `collector_status.php` | verification page |
