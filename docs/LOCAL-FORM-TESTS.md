# Complete local form verification — 1.0.0-rc3

Date: 2026-11-07, Asia/Tehran. Target: the owner's existing localhost WordPress and existing database, in its explicit local-only OTP mode. Source branch: codex/ani-sms-v1. No main merge, real credential, real SMS or API transport was used.

## Before/after evidence

The initial browser run used the actual configured 120-second lifetime, 60-second cooldown and five-attempt cap. Three parallel private browser contexts exercised the requested flows without shortening settings, editing database timestamps or simulating clocks. It completed 32 checks: 29 passed and three UI checks failed. The backend rejected expired/locked codes correctly, but the old form left confirmation enabled after lock/expiry and left an expired test code visible.

The corrected full browser suite passed **36/36** checks. The additional assertions verify field validation and recovery as well as server decisions.

| Scenario | Result |
|---|---|
| Incorrect code | HTTP rejection, localized error, aria-invalid and selectable input |
| Five incorrect attempts | Challenge locks, confirmation disables, correct old code still rejected |
| Recovery from lock | New code can be requested after the real cooldown and completes local registration |
| Actual expiration | After real elapsed lifetime, stale input/test code clears and confirmation disables |
| Expired code submitted directly | Server still rejects it independently of the browser timer |
| Resend after expiration | Fresh challenge restores the input/confirmation state and can register |
| Premature resend | Browser disables it; direct request receives 429 |
| Accepted resend | New challenge is issued; the previous challenge can no longer verify |
| Registration | New local subscriber receives standard WordPress authentication cookies |
| Repeat login | After a real cooldown, another private context authenticates the same WordPress username rather than creating a second identity |

## Network recovery follow-up

The loopback fault-injection suite passed **9/9** checks. Losing an already-processed issuance response does not cause a second challenge: retry reuses the same request ID/challenge. An aborted verification request can be retried with its still-valid code; network failure is localized and does not falsely mark the code incorrect. A malformed/null response preserves the phone form and shows safe localized feedback instead of a raw JavaScript exception.

## Implementation changes

An already browser/purpose/actor-bound challenge can return invalid/expired/locked reasons. Arbitrary identity/account lookup failures remain generic. The frontend clears terminal code data, disables confirmation and restores the form only on a fresh challenge. Input changes clear stale validation state. The request ID remains available for issuance retry after a lost response. None of these changes relax OTP storage, expiration, quotas, account restrictions or one-time consumption.

## Supporting checks and limits

The local challenge suite passed 30 assertions in each of WP 6.8/7.1 with PHP 8.3.30/8.5.12. Normal-mode integration passed 61 checks, independent-worker concurrency 6, pure checks 23, physical RC3 package activation/reactivation 6 and lifecycle 7. WPCS Core, PHP/JS syntax and PHPStan level 5 passed. Existing administrator sessions/accounts were not modified; the walkthrough creates its own local subscriber test accounts. These records are local development evidence only, not real SMS.ir delivery certification.

The generated RC3 ZIP includes 38 allowlisted plugin files and no config/database dump/private key, test transport fixtures or development tools. SHA256: `5e00f995f5d921079bed5cbc493e39045bcdbb787d599e2d25164569e75caed5`.
