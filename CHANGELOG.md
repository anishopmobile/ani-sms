# Changelog

## 1.0.0-rc3 — 2026-11-07

- Fix expired/attempt-locked form states: clear unusable codes, disable confirmation and recover after a fresh resend.
- Return localized wrong-code, expired and locked reasons only for an already browser/purpose/actor-bound challenge.
- Mark wrong-code inputs invalid, select them for correction and reset validation state on a new code.
- Add a complete real-time browser regression for wrong attempts, lock/recovery, actual expiry, resend invalidation, registration and repeat login.
- Localize network/malformed-response failures, preserve valid OTP input and recover a lost issue reply using the same request ID.

## 1.0.0-rc2 — 2026-11-07

- Add explicitly configured local-only OTP testing without an SMS.ir key or template.
- Require local environment, loopback site URL and loopback request peer; fail closed otherwise.
- Show challenge-specific test codes on the local form, preserve the normal OTP/account protections and separate test/live verification keys.
- Block the real SMS transport while the test flag is requested, label test metadata and add frontend/admin notices.

## 1.0.0-rc1 — 2026-11-07

- Implement indexed InnoDB schema, serialized identity/challenge transitions and bounded retention cleanup.
- Add SMS.ir template/connection adapter, provider registry, shared Sender and normalized non-retrying outcomes.
- Add server-only secret handling, encrypted credentials, rate limits, guest CSRF/binding and one-time OTP/account flows.
- Add normal-user linking, privileged-account restrictions, fail-closed authentication compatibility and core WordPress cookies.
- Add responsive Persian admin, independent shortcode form, standard translation catalogs and privacy lifecycle.
- Add real WordPress/MySQL integration, independent-worker concurrency, lifecycle, Chrome/axe checks, PHPStan and WPCS Core tooling.
- Package only original ANI-SMS distribution files. Real-account operational verification remains outstanding; no main merge or real SMS.

## 0.1.0-dev — 2026-11-06

- Finalize requirements 2.1 and record the implementation plan.
- Start independent WordPress plugin bootstrap and module registry.
- Add Iran-only mobile normalization and 23 isolated checks.
- No SMS or authentication functionality enabled yet.
