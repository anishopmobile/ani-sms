# Product discount alerts — 1.4.0-rc1, 2026-11-07

Implemented the authorized optional discount module on codex/ani-sms-v1: exact native sales, separate current marketing consent and product intent, immutable local/live modes, encrypted bounded queue, mandatory unsubscribe, sale-change/withdrawal cancellation, privacy and lifecycle. Owner UI: 28 assertions passed with zero browser errors; discount 44, mocked boundary 7, two-client race 4 and 51-recipient batching 6 passed. See DISCOUNT.md and DISCOUNT-TESTS.md. No real API or SMS delivery was used.

# ANI-SMS progress

## Exact-product stock alerts — 1.3.0-rc1, 2026-11-07

Completed the explicitly authorized stock module on codex/ani-sms-v1: simple/exact variation requests, stock-v1 confirmation, immutable modes, cancellation, 30-day expiry, bounded fanout/recovery, transactional settings and local simulation. Owner UI repeat passed 28 checks; stock 43, live-boundary 7 and real two-client concurrency 4 passed, plus dependency/lifecycle/order/campaign/OTP regressions. See STOCK-TESTS.md for exact environments and limits. No real API or SMS delivery was used.

## Independent consent and campaigns — 1.2.0-rc1, 2026-11-07

Completed spec phase six on codex/ani-sms-v1: pending-only import, verified explicit opt-in, suppression and token opt-out, preview-bound campaign approval, scheduling/pause/resume/cancel, generic queue integration and text/bulk transport. Schema 4 isolates local/live consent and OTP phone proof. Owner-site workflow passed 43 UI assertions; isolated suites passed marketing 42, provider 15 and live-boundary 5, plus OTP/order/concurrency/lifecycle regressions. See MARKETING-TESTS.md for exact environments and limits. No real API or SMS delivery was used.

## Optional order notifications — 1.1.0-rc1, 2026-11-07

Implemented the explicitly authorized WooCommerce phase on codex/ani-sms-v1 after the one-time rc3 main promotion. Durable encrypted queue, independent recipients/templates, transition deduplication/coalescing, cancellation guards, local previews and HPOS support are complete. Owner-site UI: 19 configuration/security/module checks and 11 actual-order checks passed after mobile/form-recovery fixes. PHP 8.3/8.5, CPT/HPOS and WooCommerce-absent regression evidence is in [WOOCOMMERCE-TESTS.md](WOOCOMMERCE-TESTS.md). No real SMS was sent.

## Complete local walkthrough and fixes — 1.0.0-rc3, 2026-11-07

Reproduced the owner's requested cases on the actual localhost site using real 120-second expiry and 60-second resend waits, without database timestamp changes or clock simulation. The RC2 baseline completed 32 browser checks: 29 passed and three UI checks failed (confirmation remained enabled after attempt lock, expired test code remained visible, and expired confirmation remained enabled). The server rejected invalid/expired/locked codes correctly in the baseline.

RC3 clears unusable input/test-code displays, disables confirmation for expired/locked challenges, preserves resend timing and reenables confirmation on a fresh challenge. Wrong entries receive focused/selectable input with aria-invalid; fresh challenges reset that state. Bound challenge failures now have localized invalid/expired/locked messages. Purpose/browser/actor checks still precede lifecycle disclosure; account lookup failures remain generic.

Pre-deployment checks: 30 local OTP assertions, 61 normal-mode regression assertions, 6 concurrency assertions, WPCS Core, PHP/JS syntax and PHPStan level 5 all pass. Complete owner-site browser results are recorded after the post-fix run. Earlier RC1/RC2 counts below remain historical evidence rather than claims that their full matrices were all repeated for RC3.

Final RC3 results: the actual owner-site real-time walkthrough passed **36/36** checks after the fixes, including lock recovery, real expiry, old-code rejection after resend, registration and repeat login to the same user. An additional **9/9** browser fault-injection checks passed for a lost issuance reply (retry reuses the original challenge), an aborted verification request (valid input stays recoverable) and malformed JSON (localized safe feedback). These runs use isolated browser sessions, leave the owner's administrator session intact and send no SMS. Local challenge-state tests passed 30 checks on all four WP 6.8/7.1 × PHP 8.3.30/8.5.12 combinations; the rest of the complete RC1 matrix was not repeated. Final pure checks: 23 PASS; concurrency: 6 PASS; physical ZIP activation/reactivation: 6 PASS; lifecycle: 7 PASS; WPCS Core and PHPStan level 5: PASS. No SQL timestamp changes or simulated browser clocks were used in the owner-site expiry/resend walkthrough.

RC3 package SHA256: `5e00f995f5d921079bed5cbc493e39045bcdbb787d599e2d25164569e75caed5` (38 allowlisted files). The installed owner checkout remains on codex/ani-sms-v1. Only the deliberate private local wp-config include differs outside committed files; secrets are not pushed. See LOCAL-FORM-TESTS.md for the before/after scenario record.

## Local-only testing increment — 1.0.0-rc2, 2026-11-07

Implemented at the owner's request: guarded loopback/local OTP simulation without an API/template, challenge-specific on-form code display, retained security/account protections, distinct test/live key IDs, no plaintext persistence/logging and an explicit block on real SMS transport while the test flag is requested. The default remains off. See ADR 0002 and README for activation and limitations.

Checks for this increment: WPCS Core and PHPStan level 5 pass; the local-test suite passes 23 assertions, the normal-mode regression suite passes (including no OTP disclosure), and the two-worker concurrency suite passes. Browser verification on the owner installation is recorded after deployment below. The earlier RC1 matrix is a historical record, not a claim that each complete matrix row was repeated for RC2.

Final RC2 evidence: 23 local-mode checks passed in all four WP 6.8/7.1 × PHP 8.3.30/8.5.12 combinations; normal-mode regression passed 61 checks, concurrency passed 6, physical RC2 ZIP installation passed 6 and lifecycle passed 7 on the disposable test installations. The actual owner-site local walkthrough passed 12 browser checks including genuine cooldown and login to the same registered account. Private configuration enables the test flag only on the localhost installation, with live sending false. No mock/API key or secret is committed. Local-test registrations create real local subscriber accounts. See LOCAL-INSTALLATION.md.

RC2 distribution: 37 allowlisted files; SHA256 `50f8c537711eda4e38d91e101f3f62de05a77af2b8f4a704c58e3e790f334e1d`. The code-entry screenshot was visually reviewed on the owner site; the six-digit value (including a leading zero), local-only warning, timer and errors render correctly.

## Release-candidate checkpoint — 2026-11-07 (Asia/Tehran)

Final specification 2.1 and development prompt are published on `codex/ani-sms-v1`. Implementation plan was committed before feature code.

Implemented the defined 1.0 feature scope as **1.0.0-rc1**: independent core and module/provider registries; InnoDB schema/migration/locks; shared SMS.ir sending boundary and encrypted credentials; complete purpose/browser/actor-bound OTP issuance/verification and unique mobile identity; registration/existing-user linking/account restrictions; standard WordPress sessions; Persian responsive admin/shortcode, redacted logs, privacy tools, hourly bounded cleanup and preserving/explicit-purge uninstall.

No real SMS credentials were used and no real messages were sent. Dummy credentials and intercepted HTTP were confined to disposable test sites. Live sending defaults off behind a server-only configuration setting. Reference code, SQL dump, wp-config and existing WordPress files remain untouched. No deferred module/provider/channel was implemented.

## Checks actually run

- Pure PHP phone/module checks: PASS, 23 assertions.
- `php -l`: PASS on all 27 distributed PHP files, including the native Persian translation catalog.
- WPCS Core with the documented namespace filename convention: PASS; generated catalog syntax checked separately.
- PHPStan level 5 with WordPress extension: PASS, no baseline or suppressed errors.
- JavaScript syntax checks: PASS for admin and frontend.
- Persian catalog: 112 entries, zero untranslated source strings.
- Real WordPress/MySQL matrix below: PASS in all four combinations, 60 integration + 6 concurrent-worker + 7 lifecycle assertions per combination.
- Real Chrome/Playwright + axe: PASS, 28 assertions including same-origin/CSRF, account cookies, admin access/nonce, stored-key secrecy, RTL/responsive widths, keyboard and plugin-scoped WCAG A/AA. Desktop/mobile screenshots were visually inspected.
- Allowlisted ZIP: PASS, 35 distribution files, archive integrity and exclusions checked.
- Physical ZIP extraction, activation, deactivation/reactivation and class-origin verification on WP 6.8: PASS, 6 assertions.
- Live SMS.ir paid send/delivery/account validation: NOT RUN, no credentials supplied or actual-message configuration authorized. An API-shape source review is not reported as a real-account smoke test.

| WordPress | PHP | MySQL | Integration | Two-worker race | Lifecycle |
|---|---|---|---|---|---|
| 6.8 official archive | 8.3.30 | 8.4.3 | 60 PASS | 6 PASS | 7 PASS |
| 6.8 official archive | 8.5.12 | 8.4.3 | 60 PASS | 6 PASS | 7 PASS |
| 7.1 provided runtime | 8.3.30 | 8.4.3 | 60 PASS | 6 PASS | 7 PASS |
| 7.1 provided runtime | 8.5.12 | 8.4.3 | 60 PASS | 6 PASS | 7 PASS |

This totals 349 asserted checks across the matrix, pure suite, browser and physical package test, plus syntax/style/static-analysis/package validations. MySQL was started with a new workspace-local data directory and loopback-only port; the original database/config was never imported or executed. PHP 8.5.12 came from the official download with SHA256 verification. WordPress update-network warnings from the deliberately blocked offline fixture were distinguished from plugin defects. Premature translation boot and a test synchronization defect were found and corrected before final passing runs.

## Status by milestone

- Phase 0: complete for this scope; decisions and test environment recorded.
- Phase 1: complete, including lifecycle/schema/settings/translation tests.
- Phase 2: adapter and mocked diagnostics complete; real-account operational verification remains an external release gate.
- Phase 3: complete for the finalized OTP/account/form scope and tested against real WordPress/MySQL with mocked SMS.
- Phase 4: code hardening, documentation, UI review and installable RC packaging complete; production certification is not claimed until the external gate is met.
- Phases 5–7: roadmap only, outside this execution scope.

## Review and next operational step

Install the generated `ani-sms-1.0.0-rc1.zip` on staging, follow README configuration, review the UI and verify the intended real SMS.ir account/template through an explicitly chosen controlled recipient. This may reveal account-specific provider limits that require a follow-up adapter adjustment. Do not merge main or publish a release without a separate instruction.

ZIP SHA256: `7a7329a273ff42eff27ff6906e334d3e6ddccf1b4cd3c013ecc14846767604f9`.

Changes are published through the connected GitHub Git-tree/commit API on `codex/ani-sms-v1`, preserving the existing parent tree because a CLI checkout was not usable. Only the original plugin and its own development docs/tests/tooling are added/updated. No test runtime, dummy admin credential, captured OTP, dependency directory or ZIP binary is committed.

## Known release prerequisites

Owner-site follow-up (2026-11-07): the actual existing local WordPress/database is now installed/configured and browser-verified, not just the disposable matrix fixtures. The owner confirmed that an SMS.ir API key has not yet been obtained and requested base setup only for now. No real send was attempted. Official IPeCompany/smsir-php was additionally reviewed and corroborates the existing base URI and verify payload. Local setup details and the remaining activation steps are in LOCAL-INSTALLATION.md; no owner password, API key, generated secret or private configuration is committed.

Official REST page https://sms.ir/rest-api/ still times out on recheck. Accessible official company TypeScript sources establish the implemented shape, not account-specific limits, approved template behavior or actual delivery. Real-account smoke testing remains outstanding. Full human screen-reader auditing and compatibility with third-party authentication plugins are not claimed; unknown authentication handlers fail closed. Multisite, MariaDB, PHP 8.4 and other browsers were not independently tested and are not presented as executed matrix rows.
