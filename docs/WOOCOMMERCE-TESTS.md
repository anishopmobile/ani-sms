# Order notification validation — 2026-11-07

Candidate: 1.1.0-rc1. Real SMS.ir delivery was not attempted; no API key is available.

## Isolated integration evidence

- Real WooCommerce 11.2.2 / WordPress 7.1 / PHP 8.3: initial 32 order assertions passed on CPT and HPOS. Expanded HPOS suite: 36 assertions including guest privacy export/erasure and expiry cleanup.
- Order scenarios: creation/customer/admin, duplicate creation/status hooks, first-transition coalescing, later changes and repeated cycles, HTML-safe five-variable rendering, encrypted payloads, no identity linking, changed billing phone, superseded statuses, changed route configuration, module off, independent recipient switches, invalid customer mobile, interrupted lease, terminal unknown, expired jobs, historical-order baseline and order deletion. Local suite asserts zero outbound HTTP.
- WordPress 6.8 without WooCommerce: 6 assertions passed for dependency message, independent auth, preserved settings, paused jobs and cancellation after module disable.
- SMS.ir multi-parameter contract and durable queue: 12 assertions passed with HTTP fully mocked. Exact verify endpoint/body, accepted ID, ambiguous no-retry, invalid configuration blocked before HTTP, terminal unknown worker and immutable local-job mode.
- Existing regression suites: pure 23, integration 61, concurrency 6 (two PHP/MySQL clients), local OTP 30, minimum-version lifecycle 7 assertions passed. Existing local OTP browser lifecycle evidence remains in LOCAL-FORM-TESTS.md.
- WordPress coding standards and PHPStan level 5 with WooCommerce stubs passed during development; release checks are repeated after final edits.

## Reproduce safely

Use only a disposable WordPress installation with `ANI_SMS_TEST_ENV=true`, environment `local`, database name ending `_test` and all external traffic blocked. Set `ANI_SMS_TEST_WP` to that installation. Activate the real WooCommerce plugin before running `tests/ani-sms/woocommerce.php`; set its store URL to localhost. The order test defines the local flag and loopback peer for that process, blocks email and intercepts all HTTP. Tests reset only plugin queue/event tables inside the guarded test database; never point them at the owner's installation. Use WooCommerce's data synchronizer before switching the disposable fixture from CPT to HPOS.

Run `order-transport.php` without the local-test server flag; its provider traffic is mocked. Run `woocommerce-absent.php` in a separate fixture without WooCommerce. `lifecycle.php` exercises explicit purge and therefore belongs only in the disposable fixture.

Production source and distributable contain no test DB credentials, local configuration, API key, SQL dump, vendor SDK, browser dependency or test harness.
