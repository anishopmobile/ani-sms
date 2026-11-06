# ANI-SMS progress

## Current checkpoint — 2026-11-06

Final specification 2.1 and development prompt are published on `codex/ani-sms-v1`. Implementation plan was committed before feature code.

Implemented initial core increment: independent plugin bootstrap, minimum-version checks, activation/deactivation preserving data, namespace autoloader, module contract and registry, explicit disabled/unavailable states, privileged read-only admin checkpoint, and Iranian mobile normalization.

No SMS credentials used; no messages sent. No authentication endpoints or incomplete settings are exposed. UI matching design.png is not implemented in this increment. Reference code, database dump, config and existing WordPress installation remain untouched.

## Checks actually run

- PHP 8.3.30: `php tests/ani-sms/run.php` — PASS, 23 checks for normalization, malformed input, registry dependency/disabled states, duplicate/late registration and idempotent boot.
- `php -l` — PASS on all 9 new PHP files.
- WordPress activation/integration, DB migrations, PHP version matrix, WPCS/static analysis, browser/RTL/accessibility and live SMS.ir tests: NOT RUN. CLI clone did not provide a usable checkout during this milestone; files were staged locally and published with the connected GitHub API while preserving the existing parent tree.

## Status by milestone

- Phase 0: repository reconnaissance and initial technical decisions recorded; runtime integration environment pending.
- Phase 1: started, not complete. Bootstrap/registry implemented. Settings, schema, translation catalog and full lifecycle tests pending.
- Phases 2–4: not implemented.
- Phases 5–7: roadmap only, outside this execution scope.

## Exact next steps

1. Establish a disposable WordPress/MySQL test environment, without importing the existing database dump or executing existing wp-config.php.
2. Implement versioned InnoDB schema and atomic identity/challenge/rate repositories. Add concurrent-request tests against MySQL.
3. Implement safe credential/configuration storage and mocked SMS.ir adapter. Revalidate official REST contract before operational sending.
4. Implement complete OTP/account module and frontend together; register public routes only when all security requirements pass.
5. Finish admin design, retention/privacy tools and release hardening from the specification.

## Known release prerequisites

Official REST page https://sms.ir/rest-api/ still times out on recheck. SDK evidence alone does not establish current payload/response limits. No production-readiness or complete release 1.0 claim is made.
