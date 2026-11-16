# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `wp cron-doctor baseline save|show <file>`: record the current warnings and
  critical findings in a JSON file, optionally for some checks only (`--check`) or
  for a limited time (`--expires`).
- `wp cron-doctor report --baseline=<file>`: findings recorded in the baseline are
  reported as `info` and no longer change the exit code or Nagios status, unless
  they got worse or list new hooks; checks fixed since the baseline are listed.

## [0.1.0] - 2026-11-13

### Added

- `wp cron-doctor report` with 15 checks: runner (DISABLE_WP_CRON against the last
  recorded run), alternate-cron, cron-lock, loopback, overdue, duplicates, orphans,
  frequent, unknown-schedules, option-size, integrity, action-scheduler,
  as-past-due, as-failed and as-stuck.
- Output as table, JSON, CSV, YAML, count or a Nagios/Icinga status line with
  performance data; exit codes 0 (ok), 1 (warning), 2 (critical), 3 (unknown) and
  `--fail-on`.
- `--check=<check>` detail rows, `--problems`, and 17 threshold options that accept
  durations (`15m`, `1h30m`) and sizes (`256K`, `1M`).
- `wp cron-doctor dedupe` and `wp cron-doctor clean-orphans`, dry run by default,
  `--yes` to apply, `--hook` / `--ignore-hook` with `*` patterns.
- `wp cron-doctor run-overdue` with `--limit`, `--grace`, `--hook`, `--exclude-hook`
  and `--dry-run`; events are rescheduled and run the way wp-cron.php does it.
- `wp cron-doctor system-cron`: crontab line (with flock) and systemd service and
  timer for the WP-CLI, curl or wget method, multisite networks included.
- `--cron-context` to load WordPress as a cron request before callbacks are inspected.
- Last-run heartbeat recorded after `wp cron event run --due-now` and, in plugin
  mode, on every cron request.
- Plugin mode with a read-only Tools > Cron Doctor page.
- Filters `wp_cron_doctor_ignored_hooks`, `wp_cron_doctor_loopback_args` and
  `wp_cron_doctor_capability`.

[Unreleased]: https://github.com/saizul/wp-cron-doctor/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/saizul/wp-cron-doctor/releases/tag/v0.1.0
