# ADR 0001 — final 1.0 implementation choices

Status: accepted under the user's explicit technical delegation.

- New original plugin source lives at `wp-content/plugins/ani-sms/` inside the existing repository. Core WordPress, wp-config, SQL dump and Plugin-sms-ir remain untouched. No merge into main or PR creation is authorized.
- Bootstrap runs at init priority 5, after WordPress globals exist and early enough for admin/AJAX/shortcode hooks. This also prevents premature translation loading. A namespace loader uses class-name file paths; the WPCS filename convention alone is excluded for this explicit PSR-style convention.
- WordPress minimum 6.8, PHP minimum 8.3, sodium and InnoDB are required. Multisite is refused. Compatibility tests include the repository's WP 7.1 runtime and an official WP 6.8 download, with PHP 8.3.30 and 8.5.12.
- MySQL connection-scoped named phone locks serialize issue/verify across workers. InnoDB transactions and unique identity constraints protect database state; no HTTP call is held inside a DB transaction. Failed-attempt increments commit. Core user creation and mapping share the verification transaction, with cache cleanup after either outcome. External plugin hook effects cannot be undone by a database rollback.
- Fixed-window limits begin on the first use of each phone/IP/site key, with atomic UPSERT counters. They are basic throttling, not an advanced velocity product. Browser and challenge replacement do not reset them. No untrusted proxy header is read.
- All valid-number issuance uses the same provider path, even for an ineligible account, to avoid a registration lookup timing oracle. Such requests may cost a message; eligibility still gates activation/consumption. Rate/site budgets bound this cost. Registration stays off by default.
- Linking an existing, nonprivileged user requires a current normal login, password reauthentication, browser/nonce binding and the new-number OTP. An already-linked identity is read-only. Unknown third-party authentication handlers fail closed; no automatic billing/email migration or account merging exists.
- Live sending is a server-only explicit setting, not enabled by activation. Source-shape evidence was available from official company TypeScript; real-account validation remains separate. No real SMS was sent during implementation.
- Hourly bounded cleanup with external cron recommended supports the retention targets; cleanup lateness is visible. Timestamp expiration and attempt checks remain correct even when cron stops.
- Bulk queues and delivery polling are unnecessary for 1.0's synchronous short-lived OTP subset and remain future work. Provider capability flags explicitly report unsupported operations. All deferred providers/channels/modules remain absent.
