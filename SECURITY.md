# Security policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | yes       |

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability reporting
("Security" tab, "Report a vulnerability") on
<https://github.com/saizul/wp-cron-doctor>, or contact the maintainer through
<https://saizul.com>.

Include the affected version, a description and steps to reproduce. You can expect
an acknowledgement within 7 days. Please give us reasonable time to release a fix
before disclosing the issue.

## How the package stays safe

- Commands that change data (`dedupe`, `clean-orphans`) only preview by default and
  need `--yes`. They use the WordPress cron API (`wp_unschedule_event()`,
  `wp_reschedule_event()`), never raw writes to the `cron` option.
- Action Scheduler is only read, with prepared queries; table names are built from
  `$wpdb->prefix` with any character other than letters, digits and `_` removed.
- The loopback test sends a `POST` with a body to the site's own `wp-cron.php`, which
  makes `wp-cron.php` exit before loading WordPress, so no event runs. Redirects are
  not followed.
- The admin page is read-only, requires `manage_options` (filterable with
  `wp_cron_doctor_capability`), runs the loopback test only with a valid nonce and
  escapes all output, including hook names and arguments.
- The only data stored is the `wp_cron_doctor_last_run` option (a timestamp and a
  source label); deleting the plugin removes it.

Event arguments can contain personal data that other plugins put there. The
`report` output shows them; treat saved reports accordingly.
