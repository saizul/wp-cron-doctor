# wp-cron-doctor

[![CI](https://github.com/saizul/wp-cron-doctor/actions/workflows/ci.yml/badge.svg)](https://github.com/saizul/wp-cron-doctor/actions/workflows/ci.yml)
[![Release zip](https://github.com/saizul/wp-cron-doctor/actions/workflows/release-zip.yml/badge.svg)](https://github.com/saizul/wp-cron-doctor/actions/workflows/release-zip.yml)
![PHP](https://img.shields.io/badge/PHP-7.4%20to%208.4-777bb4)
![WP-CLI](https://img.shields.io/badge/WP--CLI-package-3858e9)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A WP-CLI package (and an optional small plugin) that finds out **why WP-Cron and
Action Scheduler are not doing their job**, fixes the common causes and gives
monitoring systems a clean exit code.

```text
$ wp cron-doctor report
+-------------------+----------+--------------------------------------------------------------------------------+
| check             | status   | summary                                                                        |
+-------------------+----------+--------------------------------------------------------------------------------+
| runner            | critical | DISABLE_WP_CRON is set, no run has been recorded and events are 2d 4h late:    |
|                   |          | no system cron detected.                                                       |
| alternate-cron    | ok       | ALTERNATE_WP_CRON is not set.                                                  |
| cron-lock         | ok       | No cron lock is held.                                                          |
| loopback          | info     | wp-cron.php is blocked (HTTP 403). WP-Cron does not depend on it because       |
|                   |          | DISABLE_WP_CRON is set.                                                        |
| overdue           | critical | 41 event(s) overdue by more than 10m; the oldest is 2d 4h late.                |
| duplicates        | warning  | 3 hook/argument combination(s) are scheduled more than once (12 surplus        |
|                   |          | event(s)).                                                                     |
| orphans           | warning  | 2 hook(s) with 5 event(s) have no callback (left behind by removed plugins?).  |
| frequent          | ok       | No recurring event runs more often than every 5m.                              |
| unknown-schedules | warning  | 1 hook(s) use a recurrence that is no longer registered.                       |
| option-size       | warning  | The cron option is 301.4 KB with 1288 event(s).                                |
| integrity         | ok       | The cron option is well formed (1288 events).                                  |
| action-scheduler  | ok       | 1840 pending, 0 in progress, 12 failed, 50311 complete, 4 canceled.            |
| as-past-due       | critical | 1702 pending action(s) are past due; the oldest by 2d 3h.                      |
| as-failed         | warning  | 12 action(s) failed in the last 1w. Top hook:                                  |
|                   |          | woocommerce_deliver_webhook_async (7).                                         |
| as-stuck          | ok       | No action has been in progress for more than 1h.                               |
+-------------------+----------+--------------------------------------------------------------------------------+
Overall: CRITICAL (3 critical, 5 warning, 1 info, 6 ok). Details: wp cron-doctor report --check=<check>
```

*(Illustrative output.)*

## Features

- **15 checks** covering the usual WP-Cron failure modes:
  - **overdue** events and how late they are (`--overdue-warning`, `--overdue-critical`);
  - **duplicates**: the same hook with the same arguments scheduled at several
    timestamps, typically a plugin calling `wp_schedule_event()` without
    `wp_next_scheduled()`;
  - **orphans**: scheduled hooks with no callback, left behind by removed plugins;
  - **frequent** recurrences (every few seconds or minutes) and **unknown-schedules**
    (a recurrence whose `cron_schedules` filter is gone);
  - **option-size** of the autoloaded `cron` option and the hooks that fill it;
  - **integrity** of the option: malformed entries, a missing `version` key, events
    stored under a key that does not match `md5( serialize( $args ) )` (those can
    never be removed with `wp_unschedule_event()`);
  - **runner**: `DISABLE_WP_CRON` with no system cron detected, judged from the
    recorded last run, the `doing_cron` lock and the age of overdue events;
  - **alternate-cron** (`ALTERNATE_WP_CRON`) and **cron-lock** (a `doing_cron` lock
    that was never released, meaning the last run died or the spawn request never
    arrived);
  - **loopback**: a harmless request from the server to its own `wp-cron.php`;
  - **Action Scheduler**: pending, past-due (with the oldest age), recently failed
    (with the top failing hooks) and stuck in-progress actions.
- **Repairs**: `dedupe`, `clean-orphans` (both dry run by default) and `run-overdue`.
- **System cron generator**: a crontab line with `flock` and a systemd service and
  timer, for WP-CLI, curl or wget, single sites and multisite networks.
- **Monitoring friendly**: table, JSON, CSV, YAML, count or a Nagios/Icinga status
  line with performance data; exit codes `0` ok, `1` warning, `2` critical, `3`
  unknown.
- **Baselines**: accept the problems a site has today (`baseline save`) and let
  `report --baseline` alert only when something new breaks or gets worse.
- **Plugin mode** (optional): a read-only **Tools > Cron Doctor** page and a
  heartbeat recorded on every real cron request.
- **Pure core**: every check works on plain data (the cron array and Action
  Scheduler rows) and is unit tested without WordPress.

## Requirements

- PHP 7.4 to 8.4
- WordPress 5.7 or newer (`wp_unschedule_event()` with `$wp_error`)
- WP-CLI 2.x for the commands
- Action Scheduler 3.0+ (custom tables) for the Action Scheduler checks; optional

## Installation

### As a WP-CLI package (recommended)

```bash
wp package install https://github.com/saizul/wp-cron-doctor.git
# once the package is listed on Packagist:
wp package install saizul/wp-cron-doctor
```

Check it is there with `wp cron-doctor --help`.

### As a plugin (adds the admin page and the heartbeat)

Download `wp-cron-doctor.zip` from the
[latest release](https://github.com/saizul/wp-cron-doctor/releases), upload it under
**Plugins > Add New > Upload Plugin** and activate it. The plugin also registers the
`wp cron-doctor` command when WP-CLI runs, so the package is not needed on top of it
(only `--cron-context` needs the package, see below).

## Usage

```bash
# Everything, as a table.
wp cron-doctor report

# Only problems, machine readable.
wp cron-doctor report --problems --format=json

# Which events are late, which hooks are orphaned?
wp cron-doctor report --check=overdue
wp cron-doctor report --check=orphans --format=csv

# Remove duplicates (preview, then apply).
wp cron-doctor dedupe
wp cron-doctor dedupe --yes

# Run the 25 oldest overdue events now.
wp cron-doctor run-overdue --limit=25

# Get a working system cron for this site.
wp cron-doctor system-cron
```

## Command reference

### `wp cron-doctor report`

Runs all checks. The table shows `check`, `status` and `summary`; JSON and YAML
also include `value`, `advice` and `details` (the detail rows).

| Option | Default | Description |
| --- | --- | --- |
| `--format=<format>` | `table` | `table`, `json`, `csv`, `yaml`, `count` or `nagios`. |
| `--fields=<fields>` | | Fields: `check,status,value,summary,advice,items,details`. |
| `--check=<check>` | | Print the detail rows of one check instead of the summary. |
| `--problems` | | Only warnings and critical findings. |
| `--fail-on=<level>` | `warning` | `warning`, `critical` or `never`: lowest level that gives a non-zero exit code. |
| `--skip-loopback` | | Do not send the loopback request. |
| `--loopback-timeout=<s>` | `10` | Timeout of the loopback request. |
| `--skip-action-scheduler` | | Do not query the Action Scheduler tables. |
| `--ignore-hook=<hooks>` | | Hooks (comma separated, `*` allowed) never reported as orphans. |
| `--baseline=<file>` | | Apply a baseline file written by `wp cron-doctor baseline save` (see below). |
| `--cron-context` | | Load WordPress as a cron request (`DOING_CRON`) so callbacks that plugins attach only during cron runs are seen. Package mode only. |

Thresholds (durations accept `90`, `45s`, `15m`, `2h`, `1d`, `1w`, `1h30m`; sizes
accept `512`, `256K`, `1M`, `1.5MB`):

| Option | Default | Meaning |
| --- | --- | --- |
| `--overdue-warning` | `10m` | Lateness that makes an event overdue. |
| `--overdue-critical` | `1h` | Lateness that is critical. |
| `--frequent` | `5m` | Shorter recurrences are reported. |
| `--stale-after` | `1h` | With `DISABLE_WP_CRON`, a last run older than this is critical. |
| `--lock-stale` | `10m` | A `doing_cron` lock older than this is reported. |
| `--option-size-warning` | `256K` | Cron option size warning. |
| `--option-size-critical` | `1M` | Cron option size critical. |
| `--events-warning` | `1000` | Number of events warning. |
| `--duplicates-critical` | `50` | Surplus duplicate events that are critical. |
| `--loopback-slow` | `5s` | Slower loopback responses give a warning. |
| `--as-past-due-warning` | `10m` | Age of the oldest past-due action, warning. |
| `--as-past-due-critical` | `1h` | Age of the oldest past-due action, critical. |
| `--as-failed-window` | `7d` | Failures are counted in this window. |
| `--as-failed-warning` | `1` | Failures in the window, warning. |
| `--as-failed-critical` | `100` | Failures in the window, critical. |
| `--as-stuck-after` | `1h` | In-progress actions older than this are stuck. |
| `--as-pending-warning` | `10000` | Pending actions, warning. |

Checks (`--check` values): `runner`, `alternate-cron`, `cron-lock`, `loopback`,
`overdue`, `duplicates`, `orphans`, `frequent`, `unknown-schedules`, `option-size`,
`integrity`, `action-scheduler`, `as-past-due`, `as-failed`, `as-stuck`.

### `wp cron-doctor baseline`

```bash
wp cron-doctor baseline save <file> [--check=<checks>] [--expires=<duration>] [report options]
wp cron-doctor baseline show <file> [--format=<format>]
```

Many sites have problems nobody will fix soon: orphaned events left by a removed
plugin, a theme that schedules a job every minute. A monitor that is always red is
ignored, so `save` records the current warnings and critical findings in a JSON
file, and `report --baseline=<file>` shows them as `info` with the summary prefixed
`Accepted by baseline:`. They no longer change the exit code or the Nagios status.

A recorded finding still fails the report when:

- its severity got worse (a warning that is now critical: `(worse than baseline: was warning)`);
- it lists detail rows the baseline does not know, identified by hook, arguments or
  problem text, e.g. a new orphaned hook or a new failing Action Scheduler hook
  (`(new since baseline: hook_a, hook_b)`);
- the baseline has expired (`--expires=30d`); the report then says so and applies nothing.

Checks that were not problems when the baseline was saved are never accepted. When
an accepted check is fine again, the table output lists it under "Fixed since the
baseline" so the file can be saved again. Counts, run times and lateness are not
compared, so a known orphan with more events is still accepted.

| Option | Description |
| --- | --- |
| `--check=<checks>` | Only accept these comma separated checks, e.g. `orphans,frequent`. |
| `--expires=<duration>` | Stop applying the baseline after this long, e.g. `7d`. |
| `--skip-loopback`, `--skip-action-scheduler`, `--ignore-hook`, thresholds | As for `report`; use the same values in the report that reads the file. |
| `--format=<format>` | Output of `show`: `table`, `json`, `csv`, `yaml` or `count`. |

The file holds check names, severities, the main metric and hook names with their
arguments; it is safe to commit next to your monitoring config. Invalid or missing
files give exit code `3`.

### `wp cron-doctor dedupe`

Finds events with the same hook and arguments at different timestamps and keeps the
copy that runs first. Arguments are compared exactly like WordPress does
(`md5( serialize( $args ) )`), so `1` and `"1"` are different.

| Option | Description |
| --- | --- |
| `--hook=<hooks>` | Only these hooks. |
| `--yes` | Remove the events (otherwise dry run). |
| `--format=<format>` | `table`, `json`, `csv`, `yaml` or `count`. |

### `wp cron-doctor clean-orphans`

Removes events whose hook has no callback.

| Option | Description |
| --- | --- |
| `--hook=<hooks>` | Only these hooks or `*` patterns. |
| `--ignore-hook=<hooks>` | Never remove these hooks or patterns. |
| `--cron-context` | Inspect callbacks as a cron request (package mode). |
| `--yes` | Remove the events (otherwise dry run). |
| `--format=<format>` | Output format. |

### `wp cron-doctor run-overdue`

Runs due events now, oldest first, the way `wp-cron.php` does: recurring events are
rescheduled, the event is unscheduled, then the hook fires with `wp_doing_cron()`
returning true. A failed reschedule is reported but does not stop the run (like
core); an event that cannot be unscheduled is not run, so it cannot run twice.

| Option | Default | Description |
| --- | --- | --- |
| `--limit=<n>` | `10` | Maximum events, `0` for all due events. |
| `--grace=<duration>` | `0` | Only events at least this late. |
| `--hook=<hooks>` | | Only these hooks or patterns. |
| `--exclude-hook=<hooks>` | | Skip these hooks or patterns. |
| `--dry-run` | | List the events without running them. |
| `--format=<format>` | `table` | Output format. |

### `wp cron-doctor system-cron`

Prints a crontab entry, a systemd service and timer and the `wp-config.php` line
that turns off visit-based cron.

| Option | Default | Description |
| --- | --- | --- |
| `--method=<method>` | `wp-cli` | `wp-cli` (`wp cron event run --due-now`), `curl` or `wget` (request to `wp-cron.php`). |
| `--interval=<minutes>` | `5` | 1 to 60. |
| `--type=<type>` | `all` | `all`, `crontab` or `systemd`. |
| `--wp-bin=<path>` | running phar, else `wp` | WP-CLI binary; may include an interpreter, e.g. `"php /opt/wp-cli.phar"`. |
| `--user=<user>` | owner of the WordPress files, else `www-data` | System user that runs the job. |
| `--network` | | Every site of a multisite network. |
| `--log=<file>` | `/dev/null` | Append output to a file. |
| `--lock-dir=<dir>` | `/tmp` | Directory for the `flock` lock file. |
| `--format=<format>` | `text` | `text` or `json`. |

## Examples

**Monitoring with exit codes**

```bash
wp cron-doctor report --format=nagios --skip-loopback
# CRON WARNING - 1 warning: duplicates | runner=120 overdue=0 duplicates=3 orphans=0 frequent=0 ...
echo $?   # 1
```

Use it as an Icinga/Nagios check over SSH, or in any shell based monitor:

```bash
wp cron-doctor report --problems --format=json --fail-on=critical > /tmp/cron.json \
  || mail -s "WP-Cron is broken on $(hostname)" ops@example.com < /tmp/cron.json
```

**Alert only on new problems**

```bash
wp cron-doctor baseline save /etc/wp-cron-doctor/example.json --skip-loopback --expires=30d
wp cron-doctor report --format=nagios --skip-loopback --baseline=/etc/wp-cron-doctor/example.json
# CRON OK - all checks passed | runner=120 overdue=0 duplicates=0 orphans=3 frequent=0 ...
```

**Replacing visit-based cron**

```bash
wp cron-doctor system-cron --type=crontab
# --- crontab ---
# WP-Cron for https://example.com, every 5 minute(s). Install with: crontab -u www-data -e
# flock skips a run while the previous one is still busy.
*/5 * * * * flock -n /tmp/wp-cron-example-com.lock sh -c '/usr/local/bin/wp cron event run --due-now --path=/var/www/example --url=https://example.com --quiet' > /dev/null 2>&1
```

Add the line, add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`, then
check with `wp cron-doctor report --check=runner` after a few minutes.

**Cleaning up after removed plugins**

```bash
wp cron-doctor clean-orphans --cron-context               # review the list
wp cron-doctor clean-orphans --hook='oldplugin_*' --yes   # remove what you are sure about
```

**WooCommerce backlog**

```bash
wp cron-doctor report --check=as-failed
wp cron-doctor report --check=as-past-due
wp action-scheduler run --batches=10   # Action Scheduler's own command
```

## How it decides

| Check | Warning | Critical |
| --- | --- | --- |
| overdue | an event is more than 10m late | more than 1h late |
| duplicates | any duplicate | 50 or more surplus events |
| orphans | any hook without callback | never |
| runner | visit-based cron with late events and no recent run, or `DISABLE_WP_CRON` without evidence and events 10m late | `DISABLE_WP_CRON` and last run older than 1h, or no evidence and events 1h late |
| loopback | slow (5s) or redirected | blocked, 404, 429, 5xx, timeout, transport error (only info with `DISABLE_WP_CRON`) |
| option-size | 256 KB or 1000 events | 1 MB |
| as-past-due | oldest 10m | oldest 1h |
| as-failed | 1 failure in 7 days | 100 failures |

**Where the "last run" comes from.** WordPress does not store when cron last ran, so
the package records `wp_cron_doctor_last_run` itself:

- after every `wp cron event run --due-now` (package and plugin mode), which is what
  the generated system cron runs;
- in plugin mode, at the start of every cron request, with its source (`system` for a
  request without `doing_wp_cron`, `loopback` for one spawned by a visit, `alternate`
  for `ALTERNATE_WP_CRON`). Writes are throttled to one per minute per source.

The `doing_cron` transient is used as additional evidence. With neither, the runner
check falls back to the age of overdue events.

**The loopback test** sends a `POST` with a small body to `wp-cron.php` (URL from the
core `cron_request` filter, SSL verification from `https_local_ssl_verify`, exactly
like `spawn_cron()`). `wp-cron.php` stops before loading WordPress when `$_POST` is
not empty, so the test runs no events. Redirects are not followed but reported.

## Filters (plugin mode and package)

| Filter | Purpose |
| --- | --- |
| `wp_cron_doctor_ignored_hooks` | Hooks or `*` patterns never reported or removed as orphans. |
| `wp_cron_doctor_loopback_args` | Arguments for the loopback `wp_remote_post()`. |
| `wp_cron_doctor_capability` | Capability for Tools > Cron Doctor (default `manage_options`). |

## Testing

```bash
composer install
composer test     # PHPUnit, no WordPress or database needed
composer phpcs    # WordPress Coding Standards + PHPCompatibilityWP
composer lint     # php -l
```

The checks, planners, the system cron generator and the Action Scheduler query
builder are pure PHP in `src/`. The WordPress and WP-CLI glue in `includes/` is tested
against small stubs in `tests/Stubs/` that emulate the cron API
(`wp_unschedule_event()`, `wp_reschedule_event()`), options, transients, HTTP and
`wpdb`. CI runs the suite on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4.

## Limitations

- **Orphan detection depends on context.** A hook counts as orphaned when
  `has_action()` finds no callback in the current request. Plugins that attach cron
  callbacks only in wp-admin, only when `wp_doing_cron()` is true or only under
  other conditions look orphaned. `--cron-context` helps with the second case; always
  review the dry run of `clean-orphans` before using `--yes`, and use
  `--ignore-hook` or the `wp_cron_doctor_ignored_hooks` filter for false positives.
- **The admin page runs in an admin request**, so its orphan list can differ from the
  CLI. Finding texts on the page are in English.
- **Last-run evidence needs this package.** Without the heartbeat (no plugin mode and
  a system cron that calls `wp-cron.php` over HTTP) the runner check can only infer
  from overdue events and the lock.
- **Action Scheduler** checks need the custom tables (Action Scheduler 3.0+). The old
  posts-based store is reported as not checked. Only the main site's tables are read
  on multisite; run with `--url=` per site.
- **run-overdue runs hooks inside WP-CLI.** Code that relies on a web request (for
  example `$_SERVER['HTTP_HOST']`) may behave differently, and long hooks are bound
  by the CLI's own memory and time limits.
- `dedupe` and `clean-orphans` use `wp_unschedule_event()`. Events stored under a
  mismatched key (reported by the integrity check) cannot be removed that way and are
  reported as errors.
- The generated crontab uses `flock` from util-linux; on systems without it remove
  the `flock -n <file>` prefix. The systemd units are a starting point; review them
  for your server.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) - Copyright (c) 2026 [Saizul Amin](https://saizul.com).
