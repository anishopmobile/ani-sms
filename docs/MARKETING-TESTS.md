# Marketing validation — 1.2.0-rc1

Initial isolated WordPress 7.1 / PHP 8.3 results:

- 39 marketing integration assertions passed: pending-only canonical import, explicit/versioned verified consent, no grant from login, encrypted phone/token, opaque unsubscribe URL, idempotent grant, deduplicated audience, explicit admin approval, stale preview rejection, reimport suppression, duplicate approval rejection, pause/resume, local simulation, completed state, opt-out after enqueue, old-token invalidation, cancellation, variable/HTML/schedule rejection, delayed dispatch, privacy export/erasure, minimal suppression, module disable, key-rotation fail-closed and retention cleanup. HTTP count is asserted zero.
- 15 provider assertions passed with all HTTP mocked: official bulk endpoint/line/mobile payload, one accepted message ID, ambiguous/malformed/timeout unknown results, missing line/oversized text blocked before HTTP.
- Regression: WooCommerce HPOS 36, OTP integration 61 and pure 23 assertions passed. Static analysis level 5 passed during implementation. Final checks and owner-site UI results are recorded below after deployment.

Tests require `ANI_SMS_TEST_WP` pointing to a disposable WordPress instance with `ANI_SMS_TEST_ENV=true`, local environment and database ending `_test`. `marketing.php` resets only marketing/queue tables in that guarded fixture, creates disposable subscriber accounts and blocks outbound HTTP/email. Never run this harness against the owner's database. `marketing-transport.php` mocks the fixed provider contract without the local-test constant. No API key is needed and no real SMS delivery is claimed.

## Mode isolation and initial UI evidence

Expanded marketing suite: 42 assertions passed on WP 7.1/PHP 8.3 and WP 6.8/PHP 8.5 without WooCommerce. Five live-boundary assertions passed for local/legacy proof rejection, live consent mode, local audience exclusion and HTTPS enforcement. OTP 61/local 30 regressions passed after stamping proof modes. Initial owner-site UI walkthrough passed 43 assertions (signup via actual local OTP, explicit consent, canonical pending import, draft preview/approval, refresh replay prevention, scheduling, pause/resume/cancel, simulation, public confirmation-only opt-out, opt-out after enqueue, reimport suppression, disabled-module unsubscribe, nonce/guest guards and responsive layout). The first walkthrough harness was corrected to open a collapsed preview before reading its text; no provider request occurred. Final schema-4 UI repeat is recorded below.
