# Contributing

Thanks for helping improve wp-cron-doctor. Bug reports, new checks, false positives
from real sites and documentation fixes are all welcome.

## Reporting a bug

Open an issue with:

- WordPress, PHP and WP-CLI versions, and the Action Scheduler version if relevant
- whether you use the WP-CLI package, the plugin or both
- the command you ran and its output (`--format=json` is easiest to read)
- for a wrong finding: what you expected and why

The JSON output can contain hook names, arguments and site URLs. Remove anything you
do not want to make public; never post credentials or customer data that may sit in
event arguments.

## Development setup

```bash
git clone https://github.com/saizul/wp-cron-doctor.git
cd wp-cron-doctor
composer install
composer test     # PHPUnit
composer phpcs    # WordPress Coding Standards + PHPCompatibilityWP
composer lint     # php -l
```

PHP 7.4 or newer is required. Dependencies are resolved for PHP 7.4
(`config.platform`) so the same lock file works across the CI matrix (7.4 to 8.4).

To try the command against a real site, point WP-CLI at your checkout:

```bash
wp package install /path/to/wp-cron-doctor
wp cron-doctor report --path=/path/to/wordpress
```

## Project layout

- `src/` - pure PHP: the cron array model, checks, planners, the Action Scheduler
  query builder and the system cron generator. No WordPress functions here.
- `includes/` - WordPress and WP-CLI glue: reading the snapshot, running and removing
  events, the command, the admin page.
- `tests/Unit/` - PHPUnit tests; `tests/Stubs/` emulates the parts of WordPress,
  WP-CLI and `wpdb` that `includes/` uses.

## Adding a check

1. Implement `Saizul\CronDoctor\Check\Check` in `src/Check/`. Read only from the
   `Snapshot`; if you need new data, add a field to `Snapshot` and fill it in
   `includes/SnapshotFactory.php`.
2. Register it in `Doctor::default_checks()` and its id in `Doctor::check_ids()`.
3. Add thresholds to `Thresholds` (with an option name) instead of hard-coding limits.
4. Cover ok, warning and critical cases in `tests/Unit/Check/`.
5. Document it in the README tables and in the `report` command docblock.

## Guidelines

- Keep business logic in `src/` free of WordPress functions and cover it with tests.
- Follow the WordPress Coding Standards (`composer phpcbf` fixes most issues).
- Commands that change data must default to a dry run or say exactly what they did.
- Escape all admin output and check capabilities and nonces for every request.
- New hooks start with `wp_cron_doctor_` and are documented in the README.
- Add an entry under "Unreleased" in `CHANGELOG.md`.

## Releasing

1. Update the version in `wp-cron-doctor.php` (header and `WP_CRON_DOCTOR_VERSION`),
   `includes/Plugin.php` and `CHANGELOG.md`.
2. Tag `vX.Y.Z` and publish a GitHub release. The `Release zip` workflow checks that
   the versions match and attaches `wp-cron-doctor.zip`.

By contributing you agree that your contribution is licensed under the MIT license
of this project.
