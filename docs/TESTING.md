# Testing and release evidence

## Tooling

From the repository root: `composer install`, `npm ci`. Development dependencies are locked and excluded from the plugin ZIP.

- `composer test` — pure phone/registry checks (23 assertions).
- `composer style` — WPCS Core with the documented namespace filename convention; generated translation catalogs excluded from formatting rules, included in PHP syntax checks.
- `composer analyse` — PHPStan level 5 with the WordPress extension, no error baseline.
- `node --check wp-content/plugins/ani-sms/assets/frontend.js` and the corresponding admin script.
- `python tools/translations.py` — standard Persian catalog/template generation and missing-string count.
- `python tools/package.py` — allowlisted ZIP, archive integrity check and SHA256.

## Disposable WordPress integration

Use an isolated WordPress installation and an empty database whose name ends in `_test`. Do not import the repository SQL dump or load its wp-config. Tests intentionally modify/delete their own test records. They refuse a configuration without `ANI_SMS_TEST_ENV=true`, `WP_ENVIRONMENT_TYPE='local'` and a `_test` database name.

Configure a dedicated test-only `ANI_SMS_SECRET_KEY` (32 random bytes, base64), dummy `ANI_SMS_API_KEY`, `ANI_SMS_LIVE_ENABLED=true`, `ANI_SMS_ALLOW_LOCAL_HTTP=true` and `WP_HTTP_BLOCK_EXTERNAL=true`. Bind the test web server only to 127.0.0.1. Use a dedicated test administrator named `ani_test_admin`. These settings must never be deployed.

Set `ANI_SMS_TEST_WP` to that installation. Copy the current plugin source to its plugins directory before tests if it is active, so a stale installed copy is not autoloaded. `php tests/ani-sms/prepare-browser.php` performs that sync, prepares a disposable theme/privacy page, rotates the test admin password, and installs a local-only MU mock that blocks all outside HTTP and captures fake OTP payloads outside the document root.

Run, checking each process exit code:

1. `php tests/ani-sms/integration.php` — real WordPress/MySQL operations, mocked HTTP/provider. Covers migration, crypto, payload/parsers/transport errors, identity/account policy, wrong/expired/consumed/rotated codes, resend, linking, configuration validation, quotas, privacy and cleanup.
2. `php tests/ani-sms/concurrency.php` — two independent PHP workers/DB clients with a shared barrier. Concurrent identical issue sends once; concurrent verify consumes once and creates one user/identity. Its temp files contain only fake test OTPs and are deleted on success.
3. `php tests/ani-sms/lifecycle.php` — deactivation, default-preserving uninstall and explicit purge; users remain. Run this before resetting browser fixtures because it deliberately removes plugin test data.
4. Run prepare-browser again, then `npm run test:browser` with `ANI_SMS_BROWSER_RUNTIME` pointing to the parent runtime folder, `ANI_SMS_BROWSER_URL` pointing to the loopback site, and optionally `ANI_SMS_BROWSER_EXE` to Chrome/Chromium. The default Windows path can be overridden. Playwright must have a browser executable available.

Browser checks cover Persian/RTL rendering, published privacy link, keyboard order, axe WCAG A/AA against the plugin wrappers, widths 360/768/1280 and reference 1415, cross-origin/missing-CSRF rejection, phone/OTP flow, WordPress cookies, no number-change UI for linked users, subscriber denial, stored-key secrecy, nonce-protected settings and event logs. Screenshots are local test artifacts and are visually inspected, not committed as source.

## Matrix and external limits

`node tests/ani-sms/local-complete.cjs` exercises the actual local form in parallel isolated browser contexts, with genuine server-time expiry/resend waits. Use ANI_SMS_LOCAL_BROWSER_URL and optionally ANI_SMS_LOCAL_BROWSER_ARTIFACTS. The default five-attempt setting is expected. It creates only its own local subscriber test accounts, does not reset owner tables, does not change server timestamps and does not expose codes/tokens in reports. It checks wrong/locked/expired UI, server refusal, recovery through resend, old-challenge invalidation, registration and the same-account repeat login. The full run takes a little over two minutes with the default settings.

`node tests/ani-sms/local-network.cjs` uses the same explicit loopback URL. It intercepts a local issue response after the server processes it, aborts one verification before it reaches the server, and injects a null JSON reply. Assertions verify localized recovery, original-challenge reuse, no false invalid-code state for transport failure and a successful retry. It never invokes an SMS API or changes owner database timestamps. Do not interpret these network simulations as real SMS.ir outage/delivery testing.

For 1.0.0-rc2, `php tests/ani-sms/local-test.php` adds negative environment/hostname/peer checks, zero-HTTP/no-real-SMS assertions, normal policy and retry/cooldown/one-time tests, digest storage and cross-mode rejection. It requires the same disposable local test guard as other integration suites. `node tests/ani-sms/local-browser.cjs` runs against an explicitly enabled loopback URL in `ANI_SMS_LOCAL_BROWSER_URL`, displays/uses the code, checks an incorrect code, registers a subscriber and preserves cooldown after cookie reset. Set `ANI_SMS_LOCAL_BROWSER_EXISTING_LOGIN=1` to wait the genuine cooldown and verify login to the same account. Do not point the general reset/delete integration suites at the owner's database; the browser walkthrough only creates its own subscriber and never modifies existing users.

The executed matrix and final assertion counts are recorded in PROGRESS. MySQL 8.4.3 was used. PHP 8.5.12 was downloaded from the official PHP Windows site and checked against its published SHA256. The minimum WordPress 6.8 came from the official archive; WP 7.1 was the provided repository/runtime installation. No unsupported PHP versions or multisite compatibility are claimed.

All automated sends are mocked. Real SMS.ir acceptance, delivery, account billing units and template approval are not verified by these tests. Official REST-page timeout is recorded in SMSIR-API.md. A manual controlled real-account smoke test is an outstanding operational release prerequisite, not an automated test marked as passed. Axe does not replace a complete human screen-reader audit. Core update-check warnings in the isolated offline fixture are not plugin failures; plugin warnings/fatals must still be investigated.
