# Subscribers, consent and campaigns — 1.2.0-rc1

The next documented phase (spec section 10 / phase six) is explicitly authorized by the owner. This optional module is independent of WooCommerce and authentication dispatch, and defaults off. It does not add stock/discount/abandoned-cart triggers, third-party forms, Excel import, CSV export or inbound-SMS processing.

## Consent and groups

- Add `[ani_sms_marketing]` to an uncached account page. A signed-in user must already own an ANI-SMS verified mobile identity. An unchecked explicit checkbox, missing/stale consent version or absent identity is rejected. OTP, login, purchases and imports never grant marketing consent.
- Consent version `2026-11-v1` records source, timestamp and grant/withdrawal evidence. Repeated grants are idempotent. Subscribers have unique keyed canonical-phone fingerprints, encrypted phone/token fields, a consent revision, status and one local group tag. Administrators can assign tags without granting consent.
- The plain-text import accepts at most 50 Iranian mobiles, deduplicates them and creates pending contacts only. Existing consent, group and suppression remain unchanged on reimport. No arbitrary admin “grant consent” action exists.
- Each active subscription has a random 256-bit opt-out token, encrypted at rest and indexed by a keyed hash. The unsubscribe URL exposes no phone. GET displays confirmation only; POST withdraws consent without login. The route remains available when the marketing module is disabled. Fresh explicit verified opt-in rotates the token and revision; an old link cannot revoke the new subscription.
- Account forms use same-origin POST, HTTPS (explicit loopback exception only), account nonces and per-user rate limits. Public token pages use no-cache, no-referrer and noindex headers and load no external assets. Redact unsubscribe tokens from web-server/proxy access logs.

## Campaign contract

1. Enable **Subscribers and campaigns** under Modules. Distributed defaults remain off.
2. Save a draft with title, plain text (up to 1000 characters), optional exact group tag and a date in the site's timezone. Blank schedule means now; the maximum horizon is seven days. The only variables are `{site_name}` and `{unsubscribe_url}`. Unknown/unbalanced placeholders and HTML are rejected. A localized unsubscribe suffix is always added if missing.
3. Preview the deduplicated eligible count and text. Initial capacity is **200 recipients per campaign**; larger audiences must be split into smaller local groups. A keyed preview fingerprint binds the draft and each current recipient revision.
4. An administrator with `manage_options` must explicitly approve the displayed audience/schedule in a nonce-protected POST. If the audience changed, preview again. The approved snapshot excludes future opt-ins, and each campaign/subscriber has one durable recipient row and one job key. Approval cannot be replayed.
5. Pause, resume or cancel through the campaign controls. Paused jobs are excluded from worker selection so they cannot starve order notifications. Cancellation/module disable/deactivation clears queued or paused snapshots and never revives them automatically. Resumption preserves the original schedule/expiry. A send already in flight cannot be recalled.
6. Before each dispatch, recheck module/campaign/mode, consent status, policy version and subscriber revision under the same recipient lock as withdrawal. Opt-out after approval cancels queued/paused work. Group edits rotate the revision and invalidate old snapshots. Terminal unknown results and interrupted workers are never automatically resent.

## Local test without an API

Use the existing private local configuration: `ANI_SMS_LOCAL_TEST=true`, environment local, loopback hostname and loopback peer. Sending line may be blank. Approve a fresh draft and wait at least five seconds before **Process eligible jobs**. The result is `simulated`, never accepted/delivered. A loopback-only admin preview resolves the per-recipient unsubscribe URL without sending it. Consent and OTP phone proofs are also mode-tagged. Live campaigns exclude local consent, and live opt-in requires a live-verified phone identity plus HTTPS. Legacy identities start unclassified and require a successful live OTP before granting live marketing consent. Local mode is immutable in stored campaigns and jobs; switching flags cannot turn simulations into real sends.

Use the existing local OTP form to register/sign in a test subscriber, then explicitly opt in on the subscription page. Test accounts and preferences are real local records; do not opt in the owner's account on their behalf. A pending import can demonstrate exclusion before the test subscriber consents.

## Real provider boundary

Marketing uses the fixed `POST https://api.sms.ir/v1/send/bulk` contract with one recipient per request: `lineNumber`, `messageText`, `mobiles`, `sendDateTime:null`. Per-recipient requests preserve suppression checks and outcome attribution. A single positive integer `data.messageIds[0]` is required for acceptance; ambiguous responses/timeouts are unknown. It never routes marketing through OTP/transactional verification templates.

Live creation requires a public HTTPS site/unsubscribe URL and a permitted sending line. Dispatch additionally needs the private API key and `ANI_SMS_LIVE_ENABLED=true`, with local mode off. The owner has no API key yet; line permission, billing, blocked-number behavior and actual delivery remain unverified. No legal or delivery guarantee is made. Source: [official SMS.ir PHP SDK service](https://github.com/IPeCompany/smsir-php/blob/main/src/SmsIrService.php), `bulkSend`; no SDK code or dependency is bundled.

## Retention and privacy

Schema 4 adds subscribers, consent evidence, campaigns and campaign recipients. Campaign body and per-subscriber phone/token are encrypted; logs contain metadata only. Jobs expire 24 hours after their scheduled time, campaign bodies/recipient history after 30 days through bounded cleanup. Consent evidence remains for subscription administration until verified erasure. Privacy export includes subscription and paginated consent history; erasure removes phone, user mapping, token, tags, evidence and campaign links while retaining only the keyed suppression fingerprint. An administrator can perform verified erasure for a contact without email. Backups/server logs require separate retention.

Suppression is a deliberate minimal retained record, not “full deletion.” It prevents imports from reactivating someone who opted out. A new verified explicit opt-in may resubscribe; explicit full-plugin purge can remove all plugin data. Marketing locks out operations if the server key ID changes, instead of silently losing keyed suppression; restore the original key or perform a reviewed migration with the old key before continuing. Do not delete the key-ID guard to bypass this protection.

WP-Cron is best effort; use a system cron every minute for live campaigns. Local simulations require a loopback HTTP request and should use the local admin process button. Unknown results require investigation, not blind resend. Completion counts reflect provider acceptance/simulation, never delivery receipts.
