# Discount validation — 1.4.0-rc1

Date: 2026-11-07. No live API key, SMS.ir account request or real SMS delivery was used.

- WordPress 7.1 / WooCommerce 11.2.2 / PHP 8.3.30: discount 44; mocked live bulk boundary 7; two independent PHP/MySQL-client race checks 4; 51-recipient batching and price-change continuation 6.
- PHP 8.5.12: discount 44 and mocked live boundary 7.
- WordPress 6.8 without WooCommerce: dependency/withdrawal 6; physical allowlisted ZIP extraction, activation, class origin and version checks 6.
- Regressions on isolated WordPress/MySQL: marketing 42, stock 43, order/HPOS 36, authentication integration 61, local mode 30, pure checks 23 and lifecycle/purge 20. Lifecycle runs only on disposable `_test` databases.
- PHPStan level 5 and WordPress Coding Standards pass. All 248 localized strings have Persian entries.

The discount suite covers consent provenance, separate product confirmation/version, encrypted storage, duplicate hooks, one-shot outcomes, sale change/end rearming, global opt-out, identity ownership, selected variation isolation, real-time scheduled sale start, invalid/equal/higher prices, out-of-stock exclusion, hidden products, expiry, immutable modes, erasure and module independence. The live boundary intercepts SMS.ir HTTP and inspects the bulk payload and unsubscribe link; it is not delivery confirmation.

The owner UI harness is tests/ani-sms/discount-browser.cjs. It requires explicit loopback URL, private temporary admin cookies, a marked fixture state file and a guarded mutator path; it never contains credentials. It exercises actual OTP signup, explicit marketing opt-in, admin validation, separate product requests, actual Woo sale editor, cancellation, variation selection, previews, global withdrawal, nonce rejection and mobile overflow.

## Operational limits

Scheduled delivery depends on functioning WP-Cron/system cron and provider eligibility. Actual line permissions, costs, delivery and real subscriber consent remain untested until a privately configured real account is available. Catalog prices are snapshots and can change before purchase. Browser screenshots and bearer unsubscribe URLs remain private and are not committed.


Owner-site final repeat: **28/28 browser assertions passed**, zero JavaScript errors, with desktop and 390px/mobile admin views and 420px account forms. Initial run additionally verified default-off state. Actual WooCommerce editor set the simple sale price; selected/sibling variations and withdrawal used a private local marked-fixture-only CRUD helper. Persian decimal commas are respected in percentage assertions. Screenshots were visually reviewed. Temporary admin sessions and HTTP/automatic-cron isolation were removed afterward. Demo sale prices are reset to no-sale for the owner’s next test; only marked products were modified. The owner’s existing login session and consent were preserved.

Release ZIP contains 65 allowlisted plugin files; private configuration, keys, database dumps, fixtures and sessions are excluded.
